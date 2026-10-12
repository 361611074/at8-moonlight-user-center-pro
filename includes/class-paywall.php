<?php
/**
 * 付费墙（用户中心 Pro）：单篇文章付费解锁（阅读 / 下载 / 图集 / 视频）。
 *
 * 自 moonlight-shop 的付费内容模块移植并独立化：
 *   - Meta Box「付费功能」：模式标签卡 + 通用配置 + 模式专属配置（post / page）。
 *   - 正文拦截：未购买时展示摘要 + 付费墙卡片；已购买追加专属资源（下载 / 图集 / 视频）。
 *   - 解锁记录：user meta `at8_mlucp_pay_unlocks`（post_id => 过期时间戳，0=永久）。
 *   - 订单：复用 at8_mlucp_order CPT（_at8_mlucp_pay_type=paywall，_at8_mlucp_pay_post=文章ID），
 *     manual 线下转账 / PayPal / Stripe 全部复用现有网关与 complete_order 流程。
 *   - 下载端点：?at8_mlucp_pw_dl=TOKEN（transient 令牌，隐藏真实地址，二次校验解锁）。
 *
 * 不包含 moonlight-shop 的积分 / 优惠码 / 推广折扣体系（商城专属，用户中心无对应模块）。
 *
 * @package AT8_Moonlight_User_Center_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class AT8_MLUCP_Paywall
{
    const UNLOCK_META = 'at8_mlucp_pay_unlocks';

    /** @var array<string,string> 付费模式（后台标签）。 */
    public static $pay_modes = array(
        'off'      => '关闭',
        'read'     => '付费阅读',
        'download' => '付费下载',
        'image'    => '付费图片',
        'video'    => '付费视频',
    );

    /** @var array<string,string> 订单时效单位（后台标签）。 */
    public static $expire_units = array(
        'hour'  => '小时',
        'day'   => '天',
        'month' => '个月',
    );

    public static function get_instance()
    {
        static $instance = null;
        if (null === $instance) {
            $instance = new self();
        }
        return $instance;
    }

    private function __construct()
    {
        add_action('add_meta_boxes', array($this, 'register_meta_box'));
        add_action('save_post', array($this, 'save_meta'), 25);
        // 正文拦截：晚于 hidecontent 的 p5（短代码已消化），与商城版本一致用 p20。
        add_filter('the_content', array($this, 'filter_content'), 20);
        // REST API（content.rendered 不走 singular 主循环）：付费内容必须单独封堵。
        add_filter('rest_prepare_post', array($this, 'rest_prepare'), 10, 2);
        add_filter('rest_prepare_page', array($this, 'rest_prepare'), 10, 2);
        add_action('wp_ajax_at8_mlucp_pw_unlock', array($this, 'ajax_unlock'));
        add_action('wp_ajax_nopriv_at8_mlucp_pw_unlock', array($this, 'ajax_unlock'));
        // 付费墙下载端点。
        add_action('template_redirect', array($this, 'handle_download'));
    }

    /* ---------- 字段定义 ---------- */

    /**
     * 全部字段（name => 类型）。meta key = _at8_mlucp_pw_{name}。
     * 会员等级价动态存储：_at8_mlucp_pw_price_{level}（每个非 free 等级一个字段）。
     */
    public static function get_fields()
    {
        return array(
            'pay_mode'           => 'key',
            'pay_auth'           => 'key',
            'price_sell'         => 'float',
            'price_original'     => 'float',
            'sales_offset'       => 'int',
            'order_expire_enabled' => 'bool',
            'order_expire_value' => 'int',
            'order_expire_unit'  => 'key',
            'pay_popup_title'    => 'text',
            'pay_popup_desc'     => 'text',
            // 付费下载
            'download_items'     => 'jsonlist',
            'download_note'      => 'text',
            'download_btn_icon'  => 'text',
            'download_btn_color' => 'text',
            'download_attrs'     => 'json',
            'demo_url'           => 'text',
            // 付费图片
            'image_gallery'      => 'text',
            'image_free_count'   => 'int',
            'image_urls'         => 'jsonlist',
            // 付费视频
            'video_items'        => 'jsonlist',
        );
    }

    private static function default_for($name)
    {
        $defaults = array(
            'pay_mode'             => 'off',
            'pay_auth'             => 'all',
            'price_sell'           => 0,
            'price_original'       => 0,
            'sales_offset'         => 0,
            'order_expire_enabled' => '0',
            'order_expire_value'   => 0,
            'order_expire_unit'    => 'day',
            'pay_popup_title'      => '',
            'pay_popup_desc'       => '',
            'download_items'       => '',
            'download_note'        => '',
            'download_btn_icon'    => '',
            'download_btn_color'   => '#7c5cff',
            'download_attrs'       => '',
            'demo_url'             => '',
            'image_gallery'        => '',
            'image_free_count'     => 0,
            'image_urls'           => '',
            'video_items'          => '',
        );
        return isset($defaults[$name]) ? $defaults[$name] : '';
    }

    /**
     * 对外 helper：读一个付费墙字段（含默认）。
     */
    public static function get($post_id, $name, $default = '')
    {
        if (!isset(self::get_fields()[$name])) {
            return $default;
        }
        $val = get_post_meta($post_id, '_at8_mlucp_pw_' . $name, true);
        return ('' === $val || false === $val) ? self::default_for($name) : $val;
    }

    /* ---------- 开关与判定 ---------- */

    public static function is_pay_enabled()
    {
        return (bool) at8_mlucp_get_option('pw_enabled', 1);
    }

    /**
     * 文章是否启用付费墙（pay_mode != off 且总开关开启）。
     */
    public static function is_paywalled($post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0 || !self::is_pay_enabled()) {
            return false;
        }
        $type = get_post_type($post_id);
        if (!in_array($type, array('post', 'page'), true)) {
            return false;
        }
        return 'off' !== self::get($post_id, 'pay_mode');
    }

    /**
     * 当前用户是否已解锁（管理员永远可见）。
     */
    public static function is_unlocked($post_id, $user_id = 0)
    {
        if (current_user_can('manage_options')) {
            return true;
        }
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id) {
            return false;
        }
        $unlocks = get_user_meta($user_id, self::UNLOCK_META, true);
        if (!is_array($unlocks) || !isset($unlocks[$post_id])) {
            return false;
        }
        $expire = (int) $unlocks[$post_id];
        if ($expire && $expire < time()) {
            unset($unlocks[$post_id]);
            update_user_meta($user_id, self::UNLOCK_META, $unlocks);
            return false;
        }
        return true;
    }

    /**
     * 当前用户是否有权购买（购买权限 pay_auth：all / 某等级及以上）。
     */
    public static function user_can_purchase($post_id, $user_id = 0)
    {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id) {
            return false;
        }
        $auth = (string) self::get($post_id, 'pay_auth', 'all');
        if ('' === $auth || 'all' === $auth) {
            return true;
        }
        if (!class_exists('AT8_MLUCP_Membership')) {
            return false;
        }
        $levels = AT8_MLUCP_Membership::get_levels();
        if (!isset($levels[$auth])) {
            return true; // 等级已被删除：不设门槛
        }
        $cur = (string) AT8_MLUCP_Membership::get_user_level($user_id);
        $cur_order  = isset($levels[$cur]['sort_order']) ? (int) $levels[$cur]['sort_order'] : 0;
        $need_order = (int) $levels[$auth]['sort_order'];
        return $cur_order >= $need_order;
    }

    /* ---------- 价格 ---------- */

    /**
     * 价格：当前用户所在等级的会员价（>0 时）优先，否则执行价。
     */
    public static function get_price_for_user($post_id, $user_id = 0)
    {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if ($user_id && class_exists('AT8_MLUCP_Membership')) {
            $level = (string) AT8_MLUCP_Membership::get_user_level($user_id);
            if ('' !== $level && 'free' !== $level) {
                $lp = (float) get_post_meta($post_id, '_at8_mlucp_pw_price_' . $level, true);
                if ($lp > 0) {
                    return $lp;
                }
            }
        }
        return (float) self::get($post_id, 'price_sell', 0);
    }

    /* ---------- 解锁 ---------- */

    /**
     * 记录解锁。expire_ts=0 表示永久。
     */
    public static function grant_unlock($post_id, $user_id, $expire_ts = 0)
    {
        $user_id = (int) $user_id;
        $post_id = (int) $post_id;
        if (!$user_id || !$post_id) {
            return false;
        }
        $unlocks = get_user_meta($user_id, self::UNLOCK_META, true);
        if (!is_array($unlocks)) {
            $unlocks = array();
        }
        $unlocks[$post_id] = (int) $expire_ts;
        update_user_meta($user_id, self::UNLOCK_META, $unlocks);
        self::bump_sales($post_id);
        do_action('at8_mlucp_pw_unlocked', $post_id, $user_id, $expire_ts);
        return true;
    }

    /**
     * 订单完成入口（由 AT8_MLUCP_Payments::complete_order 在 paywall 类型时调用）。
     * 幂等：同一订单只发一次货。
     */
    public static function grant_for_order($order_id)
    {
        $order_id = (int) $order_id;
        if (get_post_meta($order_id, '_at8_mlucp_pw_granted', true)) {
            return true;
        }
        $post_id = (int) get_post_meta($order_id, '_at8_mlucp_pay_post', true);
        $user_id = (int) get_post_meta($order_id, '_at8_mlucp_pay_user', true);
        if (!$post_id || !$user_id) {
            return false;
        }
        self::grant_unlock($post_id, $user_id, self::get_unlock_expire($post_id));
        update_post_meta($order_id, '_at8_mlucp_pw_granted', current_time('mysql'));
        return true;
    }

    /**
     * 根据订单时效设置计算解锁过期时间戳（0 = 永久）。
     */
    public static function get_unlock_expire($post_id)
    {
        if (!self::get($post_id, 'order_expire_enabled', '0')) {
            return 0;
        }
        $value = (int) self::get($post_id, 'order_expire_value', 0);
        $unit  = (string) self::get($post_id, 'order_expire_unit', 'day');
        if ($value <= 0) {
            return 0;
        }
        $secs = array('hour' => 3600, 'day' => 86400, 'month' => 2592000);
        $mult = isset($secs[$unit]) ? $secs[$unit] : 86400;
        return time() + $value * $mult;
    }

    public static function get_sales_count($post_id)
    {
        $offset = (int) self::get($post_id, 'sales_offset', 0);
        $real   = (int) get_post_meta($post_id, '_at8_mlucp_pw_sales', true);
        return $offset + $real;
    }

    private static function bump_sales($post_id)
    {
        $real = (int) get_post_meta($post_id, '_at8_mlucp_pw_sales', true);
        update_post_meta($post_id, '_at8_mlucp_pw_sales', $real + 1);
    }

    /* ---------- 正文拦截 ---------- */

    public function filter_content($content)
    {
        if (is_admin() || !self::is_pay_enabled()) {
            return $content;
        }
        // Feed：未经认证的订阅源绝不携带付费正文（无登录态，恒为锁定）。
        if (is_feed()) {
            $post_id = (int) get_the_ID();
            if ($post_id > 0 && self::is_paywalled($post_id)) {
                return '';
            }
            return $content;
        }
        // 非 singular 上下文（搜索/归档的摘要生成等）：付费正文一律不给。
        // REST 上下文 get_the_ID() 为 0，由 rest_prepare() 单独封堵。
        if (!is_singular(array('post', 'page'))) {
            $post_id = (int) get_the_ID();
            if ($post_id > 0 && self::is_paywalled($post_id)) {
                return '';
            }
            return $content;
        }
        $post_id = (int) get_the_ID();
        if ($post_id <= 0 || !self::is_paywalled($post_id)) {
            return $content;
        }
        if (self::is_unlocked($post_id)) {
            return $content . self::render_pay_resource($post_id);
        }
        // 未解锁：摘要（有手动摘要时）+ 付费墙卡片，替换整段正文（与商城版一致）。
        $teaser = has_excerpt($post_id)
            ? '<p class="at8-mlucp-pw-teaser">' . esc_html(get_the_excerpt($post_id)) . '</p>'
            : '';
        return $teaser . self::render_locked($post_id);
    }

    /**
     * REST API：付费且未解锁的文章，content.rendered 置空并标记 protected。
     * （REST 中 the_content 过滤器拿不到文章 ID，必须在 rest_prepare 层封堵。）
     */
    public function rest_prepare($response, $post)
    {
        if (!$response instanceof WP_REST_Response || !$post instanceof WP_Post) {
            return $response;
        }
        if (!self::is_pay_enabled() || !self::is_paywalled($post->ID) || self::is_unlocked($post->ID)) {
            return $response;
        }
        $data = $response->get_data();
        if (isset($data['content']) && is_array($data['content'])) {
            $data['content']['rendered']  = '';
            $data['content']['protected'] = true;
        }
        $response->set_data($data);
        return $response;
    }

    /**
     * 已解锁后按模式追加专属资源区块。
     */
    public static function render_pay_resource($post_id)
    {
        $mode = (string) self::get($post_id, 'pay_mode');
        switch ($mode) {
            case 'download':
                return self::render_download_block($post_id);
            case 'image':
                return self::render_image_gallery($post_id, false);
            case 'video':
                return self::render_video_player($post_id, false);
            default:
                return '';
        }
    }

    /* ---------- 下载模式 ---------- */

    private static function render_download_block($post_id)
    {
        $items_raw = (string) self::get($post_id, 'download_items', '');
        $items = $items_raw ? json_decode($items_raw, true) : array();
        if (!is_array($items)) {
            $items = array();
        }
        $built = array();
        foreach ($items as $it) {
            if (!is_array($it) || empty($it['url'])) {
                continue;
            }
            $built[] = array(
                'dl_url'       => self::build_download_url($post_id, (string) $it['url']),
                'label'        => isset($it['label']) && '' !== (string) $it['label'] ? (string) $it['label'] : at8_mlucp_ui_label('pw_dl_default_btn', 'Download'),
                'copy_name'    => isset($it['copy_name']) ? (string) $it['copy_name'] : '',
                'copy_content' => isset($it['copy_content']) ? (string) $it['copy_content'] : '',
            );
        }
        if (empty($built)) {
            return '';
        }
        $attrs = self::parse_attrs((string) self::get($post_id, 'download_attrs', ''));
        $note  = (string) self::get($post_id, 'download_note', '');
        $color = (string) self::get($post_id, 'download_btn_color', '#7c5cff');
        $demo  = (string) self::get($post_id, 'demo_url', '');

        $out = '<div class="at8-mlucp-pw-res at8-mlucp-pw-dl">';
        if ($demo) {
            $out .= '<p class="at8-mlucp-pw-dl-demo"><a href="' . esc_url($demo) . '" target="_blank" rel="noopener">' . esc_html(at8_mlucp_ui_label('pw_demo_link', 'Live Demo')) . '</a></p>';
        }
        $out .= '<div class="at8-mlucp-pw-dl-list">';
        foreach ($built as $it) {
            $out .= '<a class="at8-mlucp-pw-dl-btn" style="background:' . esc_attr($color) . ';border-color:' . esc_attr($color) . ';" href="' . esc_url($it['dl_url']) . '">' . esc_html($it['label']) . '</a>';
            if ('' !== $it['copy_name'] && '' !== $it['copy_content']) {
                $out .= '<span class="at8-mlucp-pw-dl-copy">' . esc_html($it['copy_name']) . ': <code>' . esc_html($it['copy_content']) . '</code></span>';
            }
        }
        $out .= '</div>';
        if ($attrs) {
            $out .= '<dl class="at8-mlucp-pw-dl-attrs">';
            foreach ($attrs as $p) {
                $out .= '<div class="at8-mlucp-pw-dl-attr"><dt>' . esc_html($p['k']) . '</dt><dd>' . esc_html($p['v']) . '</dd></div>';
            }
            $out .= '</dl>';
        }
        if ($note) {
            $out .= '<p class="at8-mlucp-pw-dl-note">' . esc_html($note) . '</p>';
        }
        $out .= '</div>';
        return $out;
    }

    private static function build_download_url($post_id, $url)
    {
        $token = self::make_pw_token($post_id, $url);
        return add_query_arg('at8_mlucp_pw_dl', $token, home_url());
    }

    private static function make_pw_token($post_id, $url)
    {
        $user_id = get_current_user_id();
        $token   = wp_generate_password(32, false);
        set_transient('at8_mlucp_pw_dl_' . $token, array(
            'user_id' => $user_id,
            'post_id' => (int) $post_id,
            'url'     => (string) $url,
        ), 3600);
        return $token;
    }

    /**
     * 下载端点：?at8_mlucp_pw_dl=TOKEN（本地附件流式输出 / 外链 302）。
     */
    public function handle_download()
    {
        if (empty($_GET['at8_mlucp_pw_dl'])) {
            return;
        }
        if (!is_user_logged_in()) {
            auth_redirect();
        }
        $token = sanitize_text_field(wp_unslash($_GET['at8_mlucp_pw_dl']));
        $data  = get_transient('at8_mlucp_pw_dl_' . $token);
        if (!$data || (int) $data['user_id'] !== get_current_user_id()) {
            wp_die(esc_html(at8_mlucp_ui_label('pw_dl_invalid', 'This download link is invalid or has expired.')));
        }
        if (!self::is_unlocked((int) $data['post_id'])) {
            wp_die(esc_html(at8_mlucp_ui_label('pw_dl_not_owned', 'You have not purchased this content.')));
        }
        $url = (string) $data['url'];
        if (is_numeric($url)) {
            $file = get_attached_file((int) $url);
            if (!$file || !file_exists($file)) {
                wp_die(esc_html(at8_mlucp_ui_label('pw_dl_missing', 'File not found.')));
            }
            header('Content-Type: application/octet-stream');
            $name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($file));
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($file));
            readfile($file);
            exit;
        }
        wp_redirect(esc_url_raw($url), 302);
        exit;
    }

    /* ---------- 图集模式 ---------- */

    private static function render_image_gallery($post_id, $locked)
    {
        $ids = array_filter(array_map('intval', explode(',', (string) self::get($post_id, 'image_gallery', ''))));
        $ext_raw = (string) self::get($post_id, 'image_urls', '');
        $ext = $ext_raw ? json_decode($ext_raw, true) : array();
        if (!is_array($ext)) {
            $ext = array();
        }
        $free = (int) self::get($post_id, 'image_free_count', 0);
        if ($locked && $free < 0) {
            $free = 0;
        }
        $items = array();
        $idx = 0;
        foreach ($ids as $id) {
            $full  = wp_get_attachment_image_url($id, 'large');
            $thumb = wp_get_attachment_image_url($id, 'medium');
            if (!$full) {
                continue;
            }
            $is_free = (!$locked || $idx < $free);
            $items[] = array(
                'thumb' => $thumb ? $thumb : $full,
                'full'  => $is_free ? $full : '',
                'free'  => $is_free,
            );
            $idx++;
        }
        foreach ($ext as $u) {
            $u = is_string($u) ? trim($u) : '';
            if ('' === $u) {
                continue;
            }
            $is_free = (!$locked || $idx < $free);
            $items[] = array(
                'thumb' => $is_free ? $u : '',
                'full'  => $is_free ? $u : '',
                'free'  => $is_free,
            );
            $idx++;
        }
        if (empty($items)) {
            return '';
        }
        $total = count($items);
        $out = '<div class="at8-mlucp-pw-res at8-mlucp-pw-gallery" data-locked="' . esc_attr($locked ? '1' : '0') . '" data-total="' . esc_attr($total) . '">';
        foreach ($items as $i => $it) {
            $cls = 'at8-mlucp-pw-gitem' . ($it['free'] ? ' is-free' : ' is-locked');
            $out .= '<figure class="' . $cls . '">';
            if ($it['free']) {
                $out .= '<img src="' . esc_url($it['thumb']) . '" loading="lazy" alt="">';
                if (!$locked && $it['full'] && $it['full'] !== $it['thumb']) {
                    $out .= '<a class="at8-mlucp-pw-gfull" href="' . esc_url($it['full']) . '" target="_blank" rel="noopener">' . esc_html(at8_mlucp_ui_label('pw_view_full', 'View Full Size')) . '</a>';
                }
            } else {
                // 锁定占位：不输出真实地址。
                $out .= '<div class="at8-mlucp-pw-gplaceholder"><span>🔒 ' . esc_html(at8_mlucp_ui_label('pw_locked_item', 'Locked')) . '</span></div>';
            }
            $out .= '</figure>';
        }
        $out .= '</div>';
        return $out;
    }

    /* ---------- 视频模式 ---------- */

    private static function render_video_player($post_id, $locked)
    {
        $items_raw = (string) self::get($post_id, 'video_items', '');
        $items = $items_raw ? json_decode($items_raw, true) : array();
        if (!is_array($items)) {
            $items = array();
        }
        $built = array();
        foreach ($items as $it) {
            if (!is_array($it) || empty($it['url'])) {
                continue;
            }
            $cover = isset($it['cover']) ? (int) $it['cover'] : 0;
            $built[] = array(
                'url'       => (string) $it['url'],
                'cover_url' => $cover ? wp_get_attachment_image_url($cover, 'large') : '',
                'title'     => isset($it['title']) ? (string) $it['title'] : '',
            );
        }
        if (empty($built)) {
            return '';
        }
        $out = '<div class="at8-mlucp-pw-res at8-mlucp-pw-videos" data-locked="' . esc_attr($locked ? '1' : '0') . '">';
        foreach ($built as $it) {
            $out .= '<div class="at8-mlucp-pw-video">';
            if ('' !== $it['title']) {
                $out .= '<p class="at8-mlucp-pw-video-title">' . esc_html($it['title']) . '</p>';
            }
            if ($locked) {
                // 锁定态只渲染封面（不暴露视频地址）。
                $out .= '<div class="at8-mlucp-pw-video-lock" style="' . ($it['cover_url'] ? 'background-image:url(' . esc_url($it['cover_url']) . ');' : '') . '">';
                $out .= '<span class="at8-mlucp-pw-video-lock-badge">🔒 ' . esc_html(at8_mlucp_ui_label('pw_locked_video', 'Locked video. Purchase to watch.')) . '</span>';
                $out .= '</div>';
            } else {
                $out .= wp_video_shortcode(array('src' => $it['url'], 'preload' => 'metadata'));
            }
            $out .= '</div>';
        }
        $out .= '</div>';
        return $out;
    }

    private static function parse_attrs($raw)
    {
        if (!$raw) {
            return array();
        }
        $arr = json_decode((string) $raw, true);
        if (!is_array($arr)) {
            return array();
        }
        $out = array();
        foreach ($arr as $row) {
            if (is_array($row) && isset($row['k'], $row['v']) && '' !== $row['k']) {
                $out[] = array('k' => (string) $row['k'], 'v' => (string) $row['v']);
            }
        }
        return $out;
    }

    /* ---------- 付费墙卡片 ---------- */

    /**
     * 渲染锁定卡片（标题 / 说明 / 备注 / 价格 / 网关 / 解锁按钮 / 内联交互脚本）。
     */
    public static function render_locked($post_id)
    {
        static $done = array();
        if (isset($done[$post_id])) {
            return $done[$post_id];
        }

        $mode        = (string) self::get($post_id, 'pay_mode');
        $mode_labels = array(
            'read'     => at8_mlucp_ui_label('pw_mode_read', 'Paid Read'),
            'download' => at8_mlucp_ui_label('pw_mode_download', 'Paid Download'),
            'image'    => at8_mlucp_ui_label('pw_mode_image', 'Paid Gallery'),
            'video'    => at8_mlucp_ui_label('pw_mode_video', 'Paid Video'),
        );
        $mode_label = isset($mode_labels[$mode]) ? $mode_labels[$mode] : at8_mlucp_ui_label('pw_mode_read', 'Paid Read');

        $title = trim((string) self::get($post_id, 'pay_popup_title', ''));
        if ('' === $title) {
            $title = get_the_title($post_id);
        }
        if ('' === $title) {
            $title = at8_mlucp_ui_label('pw_default_title', 'Premium Content');
        }
        $desc = trim((string) self::get($post_id, 'pay_popup_desc', ''));

        $is_logged_in = is_user_logged_in();
        $can_purchase = self::user_can_purchase($post_id);
        $auth_message = '';
        if ($is_logged_in && !$can_purchase) {
            $auth = (string) self::get($post_id, 'pay_auth', 'all');
            $label = ('' !== $auth && 'all' !== $auth && class_exists('AT8_MLUCP_Membership'))
                ? AT8_MLUCP_Membership::get_level_label($auth)
                : '';
            $auth_message = $label
                ? sprintf(at8_mlucp_ui_label('pw_gated_level', 'This content is available to %s members and above. Please upgrade your membership.'), $label)
                : at8_mlucp_ui_label('pw_gated', 'Your membership level cannot purchase this content. Please upgrade your membership.');
        }

        $price    = self::get_price_for_user($post_id);
        $original = (float) self::get($post_id, 'price_original', 0);
        $symbol   = class_exists('AT8_MLUCP_Payments') ? AT8_MLUCP_Payments::get_currency_symbol() : '$';
        $fmt      = static function ($v) use ($symbol) {
            return $symbol . number_format((float) $v, 2);
        };

        $notes = array();
        $sales = self::get_sales_count($post_id);
        if ($sales > 0) {
            $notes[] = sprintf(at8_mlucp_ui_label('pw_sales_note', 'Sold: %d'), $sales);
        }
        if (self::get($post_id, 'order_expire_enabled', '0')) {
            $value = (int) self::get($post_id, 'order_expire_value', 0);
            $unit  = (string) self::get($post_id, 'order_expire_unit', 'day');
            $units = array('hour' => at8_mlucp_ui_label('pw_unit_hour', 'hour(s)'), 'day' => at8_mlucp_ui_label('pw_unit_day', 'day(s)'), 'month' => at8_mlucp_ui_label('pw_unit_month', 'month(s)'));
            $u = isset($units[$unit]) ? $units[$unit] : $unit;
            $notes[] = sprintf(at8_mlucp_ui_label('pw_expire_note', 'Access expires %d %s after purchase.'), $value, $u);
        }

        $gateways = class_exists('AT8_MLUCP_Payments') ? AT8_MLUCP_Payments::get_gateways() : array();
        $login_url = function_exists('at8_mlucp_get_login_url') ? at8_mlucp_get_login_url(get_permalink($post_id)) : wp_login_url(get_permalink($post_id));

        $paypal_on = class_exists('AT8_MLUCP_PayPal') && AT8_MLUCP_PayPal::enabled() && isset($gateways['paypal']);
        $stripe_on = class_exists('AT8_MLUCP_Stripe') && AT8_MLUCP_Stripe::enabled() && isset($gateways['stripe']);
        $manual_on = isset($gateways['manual']);

        ob_start();
        ?>
        <div class="at8-mlucp-pw" id="at8-mlucp-pw-<?php echo (int) $post_id; ?>" data-post-id="<?php echo (int) $post_id; ?>" role="region" aria-label="<?php echo esc_attr($mode_label); ?>">
            <div class="at8-mlucp-pw-head">
                <span class="at8-mlucp-pw-badge"><?php echo esc_html($mode_label); ?></span>
                <h3 class="at8-mlucp-pw-title"><?php echo esc_html($title); ?></h3>
            </div>
            <?php if ('' !== $desc) : ?>
                <p class="at8-mlucp-pw-desc"><?php echo esc_html($desc); ?></p>
            <?php endif; ?>
            <?php if ($notes) : ?>
                <ul class="at8-mlucp-pw-notes">
                    <?php foreach ($notes as $n) : ?>
                        <li><?php echo esc_html($n); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <div class="at8-mlucp-pw-action">
                <?php if (!$is_logged_in) : ?>
                    <p class="at8-mlucp-pw-login">
                        <a class="at8-mlucp-pw-btn" href="<?php echo esc_url($login_url); ?>"><?php echo esc_html(at8_mlucp_ui_label('pw_login_btn', 'Log In to Purchase')); ?></a>
                    </p>
                <?php elseif (!$can_purchase) : ?>
                    <p class="at8-mlucp-pw-gated"><?php echo esc_html($auth_message); ?></p>
                <?php else : ?>
                    <div class="at8-mlucp-pw-price">
                        <span class="at8-mlucp-pw-cost"><?php echo esc_html($fmt($price)); ?></span>
                        <?php if ($original > $price && $original > 0) : ?>
                            <span class="at8-mlucp-pw-origin"><?php echo esc_html($fmt($original)); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($gateways) : ?>
                        <div class="at8-mlucp-pw-gateways">
                            <?php $first = true; foreach ($gateways as $gid => $gtitle) : ?>
                                <label class="at8-mlucp-pw-gateway">
                                    <input type="radio" name="at8_mlucp_pw_gateway" value="<?php echo esc_attr($gid); ?>" <?php checked($first); ?>>
                                    <span><?php echo esc_html($gtitle); ?></span>
                                </label>
                            <?php $first = false; endforeach; ?>
                        </div>
                    <?php else : ?>
                        <p class="at8-mlucp-pw-nogw"><?php echo esc_html(at8_mlucp_ui_label('buy_no_gateway', 'No payment method is available. Please contact the administrator.')); ?></p>
                    <?php endif; ?>
                    <button type="button" class="at8-mlucp-pw-btn at8-mlucp-pw-unlock" data-post-id="<?php echo (int) $post_id; ?>">
                        <?php echo esc_html(at8_mlucp_ui_label('pw_btn_unlock', 'Unlock Now')); ?>
                    </button>
                    <div class="at8-mlucp-pw-pp-mount" hidden aria-label="PayPal"></div>
                    <p class="at8-mlucp-pw-msg" aria-live="polite" hidden></p>
                    <?php if ($manual_on) : ?>
                        <div class="at8-mlucp-pw-instructions" hidden>
                            <p class="at8-mlucp-pw-instructions-title"><?php echo esc_html(at8_mlucp_ui_label('pw_instructions_title', 'Payment Instructions')); ?></p>
                            <div class="at8-mlucp-pw-instructions-body"><?php echo wp_kses_post(class_exists('AT8_MLUCP_Payments') ? AT8_MLUCP_Payments::get_instructions() : ''); ?></div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
        $html = ob_get_clean();

        // 内联交互脚本：每页只输出一次。
        if (!self::$script_printed) {
            $html .= self::get_inline_script();
            self::$script_printed = true;
        }
        $done[$post_id] = $html;
        return $html;
    }

    private static $script_printed = false;

    /**
     * 锁定卡内联交互脚本（创建订单 → 线下转账提示 / Stripe 跳转 / PayPal 弹窗）。
     */
    private static function get_inline_script()
    {
        $paypal_on = class_exists('AT8_MLUCP_PayPal') && AT8_MLUCP_PayPal::enabled();
        $sdk = $paypal_on ? AT8_MLUCP_PayPal::sdk_url() : '';
        $i18n = array(
            'ok_reload'      => at8_mlucp_ui_label('pw_ok_reload', 'Payment successful. Reloading…'),
            'manual_done'    => at8_mlucp_ui_label('pw_order_manual', 'Order created. Please complete the bank transfer below. The content will be unlocked once the administrator confirms your payment.'),
            'stripe_goon'    => at8_mlucp_ui_label('pw_order_stripe', 'Redirecting to Stripe…'),
            'pp_goto'        => at8_mlucp_ui_label('buy_pp_goto', 'Please complete the PayPal payment below.'),
            'pp_load_fail'   => at8_mlucp_ui_label('buy_pp_load_fail', 'Failed to load PayPal. Please refresh the page and try again.'),
            'pp_confirming'  => at8_mlucp_ui_label('buy_pp_confirming', 'Confirming payment...'),
            'pp_error'       => at8_mlucp_ui_label('buy_pp_error', 'PayPal payment error. Please try again or contact the administrator.'),
            'net_error'      => at8_mlucp_ui_label('buy_net_error', 'Network error. Please try again later.'),
            'op_failed'      => at8_mlucp_ui_label('av_op_failed', 'Operation failed.'),
        );
        $data = array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('at8_mlucp_nonce'),
            'i18n'     => $i18n,
            'paypal_on' => $paypal_on,
        );
        $cfg = wp_json_encode($data);
        $sdk_tag = $sdk ? '<script src="' . esc_url($sdk) . '" data-partner-attribution-id="moonlight_user_center"></script>' : '';
        ?>
        <?php echo $sdk_tag; // phpcs:ignore WordPress.Security.EscapeOutput ?>
        <script>
        (function () {
            'use strict';
            var CFG = <?php echo $cfg; // phpcs:ignore WordPress.Security.EscapeOutput ?>;
            function post(action, params) {
                var fd = new FormData();
                fd.append('action', action);
                fd.append('nonce', CFG.nonce);
                Object.keys(params || {}).forEach(function (k) { fd.append(k, params[k]); });
                return fetch(CFG.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function (r) { return r.json(); });
            }
            function say(card, msg, isError) {
                var el = card.querySelector('.at8-mlucp-pw-msg');
                if (!el) { return; }
                el.hidden = false;
                el.textContent = msg;
                el.classList.toggle('is-error', !!isError);
            }
            function mountPayPal(card, orderId) {
                var mount = card.querySelector('.at8-mlucp-pw-pp-mount');
                if (!window.paypal || !mount) {
                    say(card, CFG.i18n.pp_load_fail, true);
                    return;
                }
                mount.hidden = false;
                window.paypal.Buttons({
                    style: { layout: 'vertical', label: 'paypal' },
                    createOrder: function () {
                        return post('at8_mlucp_paypal_create', { order_id: orderId }).then(function (res) {
                            if (!res.success) { throw new Error(res.message || CFG.i18n.op_failed); }
                            return res.data.pp_order_id;
                        });
                    },
                    onApprove: function (data2) {
                        say(card, CFG.i18n.pp_confirming);
                        return post('at8_mlucp_paypal_capture', { order_id: orderId, pp_order_id: data2.orderID }).then(function (res) {
                            if (!res.success) { throw new Error(res.message || CFG.i18n.op_failed); }
                            say(card, CFG.i18n.ok_reload);
                            setTimeout(function () { window.location.reload(); }, 900);
                        });
                    },
                    onError: function () { say(card, CFG.i18n.pp_error, true); }
                }).render(mount);
            }
            document.addEventListener('click', function (e) {
                var btn = e.target.closest ? e.target.closest('.at8-mlucp-pw-unlock') : null;
                if (!btn) { return; }
                var card = btn.closest('.at8-mlucp-pw');
                if (!card) { return; }
                var gwEl = card.querySelector('input[name="at8_mlucp_pw_gateway"]:checked');
                var gw = gwEl ? gwEl.value : '';
                if (!gw) { say(card, CFG.i18n.net_error, true); return; }
                btn.disabled = true;
                post('at8_mlucp_pw_unlock', { post_id: card.getAttribute('data-post-id'), gateway: gw }).then(function (res) {
                    if (!res || typeof res.success === 'undefined') { throw new Error('bad'); }
                    if (!res.success) {
                        say(card, res.message || CFG.i18n.op_failed, true);
                        btn.disabled = false;
                        return;
                    }
                    var d = res.data || {};
                    if (d.reload) {
                        say(card, res.message || CFG.i18n.ok_reload);
                        setTimeout(function () { window.location.reload(); }, 900);
                        return;
                    }
                    if ('stripe' === d.flow && d.redirect) {
                        say(card, CFG.i18n.stripe_goon);
                        window.location.href = d.redirect;
                        return;
                    }
                    if ('paypal' === d.flow && d.order_id) {
                        say(card, CFG.i18n.pp_goto);
                        mountPayPal(card, d.order_id);
                        return;
                    }
                    if (d.instructions) {
                        var ins = card.querySelector('.at8-mlucp-pw-instructions');
                        var insBody = card.querySelector('.at8-mlucp-pw-instructions-body');
                        if (insBody) { insBody.textContent = d.instructions; }
                        if (ins) { ins.hidden = false; }
                    }
                    say(card, res.message || CFG.i18n.manual_done);
                    btn.disabled = false;
                }).catch(function () {
                    say(card, CFG.i18n.net_error, true);
                    btn.disabled = false;
                });
            });
        })();
        </script>
        <?php
    }

    /* ---------- AJAX 解锁 ---------- */

    public function ajax_unlock()
    {
        check_ajax_referer('at8_mlucp_nonce', 'nonce');
        if (!is_user_logged_in()) {
            at8_mlucp_send_json(false, at8_mlucp_ui_label('buy_login_required', 'Please log in first.'));
        }
        if (!self::is_pay_enabled()) {
            at8_mlucp_send_json(false, at8_mlucp_ui_label('pw_disabled', 'Paid content is not available.'));
        }
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if (!$post_id || !self::is_paywalled($post_id)) {
            at8_mlucp_send_json(false, at8_mlucp_ui_label('pw_not_needed', 'This content does not require payment.'));
        }
        if (self::is_unlocked($post_id)) {
            at8_mlucp_send_json(true, at8_mlucp_ui_label('pw_already', 'You already have access to this content.'), array('reload' => 1));
        }
        if (!self::user_can_purchase($post_id)) {
            at8_mlucp_send_json(false, at8_mlucp_ui_label('pw_gated', 'Your membership level cannot purchase this content. Please upgrade your membership.'));
        }
        $price = self::get_price_for_user($post_id);
        if ($price <= 0) {
            at8_mlucp_send_json(false, at8_mlucp_ui_label('pw_no_price', 'This content has no price set and cannot be purchased yet.'));
        }
        $gw = isset($_POST['gateway']) ? sanitize_key(wp_unslash($_POST['gateway'])) : '';
        $gateways = class_exists('AT8_MLUCP_Payments') ? AT8_MLUCP_Payments::get_gateways() : array();
        if ('' === $gw || !isset($gateways[$gw])) {
            at8_mlucp_send_json(false, at8_mlucp_ui_label('buy_invalid_gateway', 'Invalid payment method.'));
        }

        $user_id = get_current_user_id();
        $title = sprintf('PW-%s-%s', date_i18n('YmdHis'), get_the_title($post_id) ?: $post_id);
        $cpt = class_exists('AT8_MLUCP_Payments') ? AT8_MLUCP_Payments::CPT : 'at8_mlucp_order';

        // 同一用户对同一文章已有待支付订单：复用，避免连点/重试堆出重复订单。
        $pending = get_posts(array(
            'post_type'      => $cpt,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array('key' => '_at8_mlucp_pay_user', 'value' => $user_id),
                array('key' => '_at8_mlucp_pay_type', 'value' => 'paywall'),
                array('key' => '_at8_mlucp_pay_post', 'value' => $post_id),
                array('key' => '_at8_mlucp_pay_status', 'value' => 'pending'),
            ),
        ));
        if ($pending) {
            $order_id = (int) $pending[0];
            update_post_meta($order_id, '_at8_mlucp_pay_title', get_the_title($post_id));
            update_post_meta($order_id, '_at8_mlucp_pay_price', $price);
            update_post_meta($order_id, '_at8_mlucp_pay_gateway', $gw);
        } else {
            $order_id = wp_insert_post(array(
                'post_type'   => $cpt,
                'post_status' => 'publish',
                'post_title'  => $title,
                'post_author' => $user_id,
            ));
            if (is_wp_error($order_id) || !$order_id) {
                at8_mlucp_send_json(false, at8_mlucp_ui_label('buy_order_failed', 'Failed to create the order. Please try again later.'));
            }
            update_post_meta($order_id, '_at8_mlucp_pay_user', $user_id);
            update_post_meta($order_id, '_at8_mlucp_pay_type', 'paywall');
            update_post_meta($order_id, '_at8_mlucp_pay_post', $post_id);
            update_post_meta($order_id, '_at8_mlucp_pay_title', get_the_title($post_id));
            update_post_meta($order_id, '_at8_mlucp_pay_price', $price);
            update_post_meta($order_id, '_at8_mlucp_pay_gateway', $gw);
            update_post_meta($order_id, '_at8_mlucp_pay_status', 'pending');
            do_action('at8_mlucp_pw_order_created', $order_id, $user_id, $post_id, $gw);
        }
        update_post_meta($order_id, '_at8_mlucp_pay_status', 'pending');

        if ('paypal' === $gw) {
            at8_mlucp_send_json(true, at8_mlucp_ui_label('buy_order_paypal', 'Order created. Please complete the PayPal payment.'), array(
                'order_id' => (int) $order_id,
                'flow'     => 'paypal',
            ));
        }
        if ('stripe' === $gw) {
            $session = AT8_MLUCP_Stripe::create_session($order_id);
            if (is_wp_error($session)) {
                update_post_meta($order_id, '_at8_mlucp_pay_status', 'cancelled');
                at8_mlucp_send_json(false, $session->get_error_message());
            }
            update_post_meta($order_id, '_at8_mlucp_pay_txn_ref', $session['id']);
            at8_mlucp_send_json(true, at8_mlucp_ui_label('pw_order_stripe', 'Order created. Redirecting to Stripe…'), array(
                'order_id' => (int) $order_id,
                'flow'     => 'stripe',
                'redirect' => $session['url'],
            ));
        }

        at8_mlucp_send_json(true, at8_mlucp_ui_label('pw_order_manual', 'Order created. Please complete the bank transfer as instructed. The content will be unlocked once the administrator confirms your payment.'), array(
            'order_id'     => (int) $order_id,
            'instructions' => class_exists('AT8_MLUCP_Payments') ? AT8_MLUCP_Payments::get_instructions() : '',
        ));
    }

    /* ---------- Meta Box ---------- */

    public function register_meta_box()
    {
        foreach (array('post', 'page') as $pt) {
            add_meta_box('at8_mlucp_pw_meta', '付费功能（用户中心）', array($this, 'render_meta_box_ui'), $pt, 'normal', 'high');
        }
    }

    public function render_meta_box_ui($post)
    {
        wp_nonce_field('at8_mlucp_pw_meta', 'at8_mlucp_pw_meta_nonce');
        $data = array();
        foreach (self::get_fields() as $name => $type) {
            $val = get_post_meta($post->ID, '_at8_mlucp_pw_' . $name, true);
            $data[$name] = ('' === $val || false === $val) ? self::default_for($name) : $val;
        }
        $symbol = class_exists('AT8_MLUCP_Payments') ? AT8_MLUCP_Payments::get_currency_symbol() : '$';
        $levels = class_exists('AT8_MLUCP_Membership') ? AT8_MLUCP_Membership::get_levels() : array();

        // 各等级会员价
        $level_prices = array();
        foreach ($levels as $lk => $lv) {
            if ('free' === $lk) {
                continue;
            }
            $lp = get_post_meta($post->ID, '_at8_mlucp_pw_price_' . $lk, true);
            $level_prices[$lk] = array(
                'label' => isset($lv['label']) ? (string) $lv['label'] : $lk,
                'price' => ('' === $lp || false === $lp) ? 0 : (float) $lp,
            );
        }

        // 文件信息键值对
        $attrs_pairs = array();
        if (!empty($data['download_attrs'])) {
            $tmp = json_decode((string) $data['download_attrs'], true);
            if (is_array($tmp)) {
                foreach ($tmp as $p) {
                    if (is_array($p) && isset($p[0])) {
                        $attrs_pairs[] = array((string) $p[0], (string) (isset($p[1]) ? $p[1] : ''));
                    }
                }
            }
        }
        if (empty($attrs_pairs)) {
            $attrs_pairs = array(array('', ''));
        }
        // 付费图集
        $gallery_ids = array();
        if (!empty($data['image_gallery'])) {
            $gallery_ids = array_filter(array_map('intval', explode(',', (string) $data['image_gallery'])));
        }
        // 下载资源清单
        $dl_items = array();
        if (!empty($data['download_items'])) {
            $tmp = json_decode((string) $data['download_items'], true);
            if (is_array($tmp)) {
                foreach ($tmp as $it) {
                    if (!is_array($it)) { continue; }
                    $dl_items[] = array(
                        'url'          => isset($it['url']) ? (string) $it['url'] : '',
                        'label'        => isset($it['label']) ? (string) $it['label'] : '',
                        'copy_name'    => isset($it['copy_name']) ? (string) $it['copy_name'] : '',
                        'copy_content' => isset($it['copy_content']) ? (string) $it['copy_content'] : '',
                    );
                }
            }
        }
        if (empty($dl_items)) {
            $dl_items = array(array('url' => '', 'label' => '', 'copy_name' => '', 'copy_content' => ''));
        }
        // 视频播放列表
        $vid_items = array();
        if (!empty($data['video_items'])) {
            $tmp = json_decode((string) $data['video_items'], true);
            if (is_array($tmp)) {
                foreach ($tmp as $it) {
                    if (!is_array($it)) { continue; }
                    $vid_items[] = array(
                        'url'   => isset($it['url']) ? (string) $it['url'] : '',
                        'cover' => isset($it['cover']) ? (int) $it['cover'] : 0,
                        'title' => isset($it['title']) ? (string) $it['title'] : '',
                    );
                }
            }
        }
        if (empty($vid_items)) {
            $vid_items = array(array('url' => '', 'cover' => 0, 'title' => ''));
        }
        // 外链图片
        $img_urls = array();
        if (!empty($data['image_urls'])) {
            $tmp = json_decode((string) $data['image_urls'], true);
            if (is_array($tmp)) {
                foreach ($tmp as $u) {
                    if (is_string($u) && '' !== $u) { $img_urls[] = $u; }
                }
            }
        }
        if (empty($img_urls)) {
            $img_urls = array('');
        }
        include AT8_MLUCP_PLUGIN_DIR . 'templates/paywall-meta.php';
    }

    public function save_meta($post_id)
    {
        if (!isset($_POST['at8_mlucp_pw_meta_nonce'])) {
            return;
        }
        $type = get_post_type($post_id);
        if (!in_array($type, array('post', 'page'), true)) {
            return;
        }
        if (!wp_verify_nonce($_POST['at8_mlucp_pw_meta_nonce'], 'at8_mlucp_pw_meta')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        foreach (self::get_fields() as $name => $ftype) {
            $key = '_at8_mlucp_pw_' . $name;
            if ('bool' === $ftype) {
                $val = (isset($_POST['at8_mlucp_pw_' . $name]) && $_POST['at8_mlucp_pw_' . $name]) ? '1' : '0';
                update_post_meta($post_id, $key, $val);
                continue;
            }
            if (!isset($_POST['at8_mlucp_pw_' . $name])) {
                continue;
            }
            $val = wp_unslash($_POST['at8_mlucp_pw_' . $name]);
            switch ($ftype) {
                case 'float': $val = (float) $val; break;
                case 'int':   $val = (int) $val; break;
                case 'key':   $val = sanitize_key($val); break;
                case 'json':  $val = self::sanitize_json($val); break;
                case 'jsonlist': $val = self::sanitize_json_value($val); break;
                default:      $val = sanitize_text_field($val); break;
            }
            if ('json' === $ftype || 'jsonlist' === $ftype) {
                $val = wp_slash($val);
            }
            update_post_meta($post_id, $key, $val);
        }

        // 会员等级价（动态字段：at8_mlucp_pw_level_price[level]）。
        if (isset($_POST['at8_mlucp_pw_level_price']) && is_array($_POST['at8_mlucp_pw_level_price'])) {
            $levels = class_exists('AT8_MLUCP_Membership') ? AT8_MLUCP_Membership::get_levels() : array();
            foreach ($_POST['at8_mlucp_pw_level_price'] as $lk => $lp) {
                $lk = sanitize_key($lk);
                if ('' === $lk || 'free' === $lk || !isset($levels[$lk])) {
                    continue;
                }
                update_post_meta($post_id, '_at8_mlucp_pw_price_' . $lk, (float) $lp);
            }
        }
    }

    /**
     * 校验并清洗 JSON（download_attrs：[["k","v"],...]）。
     */
    private static function sanitize_json($raw)
    {
        $raw = trim((string) $raw);
        if ('' === $raw) {
            return '';
        }
        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            return '';
        }
        $clean = array();
        foreach ($arr as $pair) {
            if (!is_array($pair) || count($pair) < 2) {
                continue;
            }
            $k = sanitize_text_field((string) $pair[0]);
            $v = sanitize_text_field((string) $pair[1]);
            if ('' === $k) {
                continue;
            }
            $clean[] = array($k, $v);
        }
        return wp_json_encode($clean, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 校验并清洗通用 JSON 列表（download_items / video_items / image_urls）。
     */
    private static function sanitize_json_value($raw)
    {
        $raw = trim((string) $raw);
        if ('' === $raw) {
            return '';
        }
        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            return '';
        }
        $clean = array();
        foreach ($arr as $el) {
            if (is_array($el)) {
                $item = array();
                foreach ($el as $k => $v) {
                    $item[sanitize_key((string) $k)] = is_array($v) ? $v : sanitize_text_field((string) $v);
                }
                $clean[] = $item;
            } elseif (is_string($el) || is_numeric($el)) {
                $clean[] = sanitize_text_field((string) $el);
            }
        }
        return wp_json_encode($clean, JSON_UNESCAPED_UNICODE);
    }
}
