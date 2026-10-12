<?php
/**
 * [at8_mlucp_hide] 短代码 + 内容过滤。
 *
 * 用法：[at8_mlucp_hide type="reply|logged|vip1|payshow"]...[/hidecontent]
 *   - reply    评论后可查看（当前用户已通过审核的评论即可解锁）
 *   - logged   登录后可查看
 *   - vip1     会员可查看（>=月费等级且未过期）
 *   - payshow  付费后可查看（商城付费墙已解锁 / 月费会员以上）
 *
 * 嵌套安全：渲染时对 $content 递归 do_shortcode，避免内层 shortcode 被外层吞掉。
 * Elementor 兼容：同时挂 elementor/frontend/the_content 与 elementor/widget/render_content
 *   （优先级 5，早于 do_shortcode 的 p11），保证 Elementor 文本小工具里的短代码也会被执行。
 *
 * @package AT8_Moonlight_User_Center_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class AT8_MLUCP_Hidecontent
{
    /** 类型注册表。 */
    public static $types = array(
        'reply'   => array('label' => '评论后可查看', 'cta' => 'Comment to View', 'icon' => '💬'),
        'logged'  => array('label' => '登录后可查看', 'cta' => 'Log in to View', 'icon' => '🔒'),
        'vip1'    => array('label' => '会员可查看',   'cta' => 'Upgrade Membership', 'icon' => '⭐'),
        'payshow' => array('label' => '付费后可查看', 'cta' => 'Buy Now', 'icon' => '💎'),
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
        add_shortcode('at8_mlucp_hide', array($this, 'render'));
        // 标准内容链 p5：早于 WP 默认 do_shortcode (p11)，先把短代码消化掉
        // （do_shortcode 幂等：第二次执行已找不到 shortcode 标签，所以不会重复渲染）
        add_filter('the_content', array($this, 'maybe_filter'), 5);
        // Elementor 兼容：文本小工具 / Elementor 渲染链
        add_filter('elementor/frontend/the_content', array($this, 'maybe_filter'), 5);
        add_filter('elementor/widget/render_content', array($this, 'maybe_filter'), 5);
    }

    /**
     * the_content 过滤器：仅在含 [at8_mlucp_hide] 的内容上跑一次 do_shortcode。
     */
    public function maybe_filter($content)
    {
        if (!is_string($content) || strpos($content, '[at8_mlucp_hide') === false) {
            return $content;
        }
        return do_shortcode($content);
    }

    /**
     * 短代码渲染。
     */
    public function render($atts, $content = '')
    {
        $atts = shortcode_atts(
            array('type' => 'logged'),
            $atts,
            'hidecontent'
        );
        $type = isset($atts['type']) ? (string) $atts['type'] : 'logged';
        if (!isset(self::$types[$type])) {
            $type = 'logged';
        }

        // 嵌套安全：内层 shortcode 也跑一次
        $inner = do_shortcode($content);

        if (self::user_can_view($type)) {
            return $inner;
        }
        return self::render_cta($type);
    }

    /**
     * 当前访问者是否有权查看指定类型的隐藏内容。
     */
    public static function user_can_view($type)
    {
        // 作者本人 / 管理员永远可见，便于预览与排错。
        // 安全：原用 edit_posts 判断，投稿者/作者等低权限角色也能看到全站任意文章的
        // 隐藏内容（权限过宽）。改为：作者本人，或具备编辑他人文章权限的角色。
        if (is_user_logged_in()
            && (current_user_can('edit_others_posts') || current_user_can('manage_options') || self::is_current_post_author())) {
            return true;
        }

        switch ($type) {
            case 'logged':
                return is_user_logged_in();

            case 'vip1':
                // 月费以上（含黄金/高级/钻石）即解锁
                return self::is_member_at_least('monthly');

            case 'payshow':
                // 会员月费以上 或 该文章商城付费墙已解锁
                if (self::is_member_at_least('monthly')) {
                    return true;
                }
                return self::is_post_paywall_unlocked();

            case 'reply':
                return self::user_has_approved_comment();

            default:
                return false;
        }
    }

    /** 当前用户等级是否 >= $key（按 sort_order 比较）。 */
    private static function is_member_at_least($key)
    {
        if (!is_user_logged_in()) {
            return false;
        }
        if (!function_exists('at8_mlucp_user_level')) {
            return false;
        }
        $levels = method_exists('AT8_MLUCP_Membership', 'get_levels')
            ? AT8_MLUCP_Membership::get_levels()
            : array();
        if (empty($levels)) {
            // 工厂默认：monthly sort_order=20
            $levels = array(
                'free'    => array('sort_order' => 10),
                'monthly' => array('sort_order' => 20),
                'gold'    => array('sort_order' => 25),
                'premium' => array('sort_order' => 30),
                'diamond' => array('sort_order' => 35),
            );
        }
        $cur = (string) at8_mlucp_user_level(get_current_user_id());
        $cur_order = isset($levels[$cur]['sort_order']) ? (int) $levels[$cur]['sort_order'] : 10;
        $min_order = isset($levels[$key]['sort_order']) ? (int) $levels[$key]['sort_order'] : 20;
        return $cur_order >= $min_order;
    }

    /** 当前文章作者。 */
    private static function is_current_post_author()
    {
        if (!is_singular() || !is_user_logged_in()) {
            return false;
        }
        $post_id = (int) get_queried_object_id();
        if (!$post_id) {
            return false;
        }
        $post = get_post($post_id);
        return $post && (int) $post->post_author === (int) get_current_user_id();
    }

    /** 当前用户是否在当前文章有已通过的评论。 */
    private static function user_has_approved_comment()
    {
        if (!is_user_logged_in() || !is_singular()) {
            return false;
        }
        $post_id = (int) get_queried_object_id();
        if (!$post_id) {
            return false;
        }
        $user = wp_get_current_user();
        $email = (string) $user->user_email;
        if ($email === '') {
            return false;
        }
        $q = new WP_Comment_Query(array(
            'post_id'      => $post_id,
            'status'       => 'approve',
            'author_email' => $email,
            'count'        => true,
        ));
        return ((int) $q->get_found_posts()) > 0;
    }

    /** 当前文章（若有）Pro 付费墙是否已为当前用户解锁。 */
    private static function is_post_paywall_unlocked()
    {
        if (!is_singular() || !is_user_logged_in()) {
            return false;
        }
        $post_id = (int) get_queried_object_id();
        if (!$post_id || !class_exists('AT8_MLUCP_Paywall')) {
            return false;
        }
        if (!AT8_MLUCP_Paywall::is_paywalled($post_id)) {
            // 文章没开付费墙，"payshow" 通用语义下退化为会员闸
            return false;
        }
        return AT8_MLUCP_Paywall::is_unlocked($post_id);
    }

    /**
     * 渲染锁定 CTA 卡片。
     */
    private static function render_cta($type)
    {
        $info   = self::$types[$type];
        $type_esc = esc_attr($type);
        // 锁定卡标题：走 at8_mlucp_ui_label（英文默认 + 后台可自定义）。
        // $info['label'] 的中文仅用于后台编辑器下拉，不输出到前台。
        $hc_titles = array(
            'reply'   => 'Comment to View',
            'logged'  => 'Log in to View',
            'vip1'    => 'Members Only',
            'payshow' => 'Purchase to View',
        );
        $title = at8_mlucp_ui_label(
            'hc_title_' . $type,
            isset($hc_titles[$type]) ? $hc_titles[$type] : 'Locked Content'
        );
        $icon   = esc_html($info['icon']);

        $login_url    = at8_mlucp_get_login_url(get_permalink());
        $account_url  = at8_mlucp_get_account_url();

        // 不同类型给不同的 CTA 链接 & 提示文案
        switch ($type) {
            case 'logged':
                $action = sprintf(
                    '<a class="at8-mlucp-hc-btn" href="%s">%s</a>',
                    esc_url($login_url),
                    esc_html__('Log In', 'at8-moonlight-user-center-pro')
                );
                $tip = esc_html__('Log in to view the content below.', 'at8-moonlight-user-center-pro');
                break;
            case 'vip1':
                $action = sprintf(
                    '<a class="at8-mlucp-hc-btn" href="%s">%s</a>',
                    esc_url(add_query_arg('tab', 'membership', $account_url)),
                    esc_html__('Upgrade Membership', 'at8-moonlight-user-center-pro')
                );
                $tip = esc_html__('This content is for Monthly members and above.', 'at8-moonlight-user-center-pro');
                break;
        case 'payshow':
            // 若当前文章启用了付费墙，跳到该文章触发解锁；否则跳会员购买
            $target = add_query_arg('tab', 'membership', $account_url);
            if (is_singular() && class_exists('AT8_MLUCP_Paywall') && AT8_MLUCP_Paywall::is_paywalled((int) get_queried_object_id())) {
                $target = get_permalink((int) get_queried_object_id());
            } elseif (!is_user_logged_in()) {
                $target = $login_url;
            }
                $action = sprintf(
                    '<a class="at8-mlucp-hc-btn" href="%s">%s</a>',
                    esc_url($target),
                    esc_html__('Buy Now', 'at8-moonlight-user-center-pro')
                );
                $tip = esc_html__('Purchase to view the content below.', 'at8-moonlight-user-center-pro');
                break;
            case 'reply':
                $action = sprintf(
                    '<a class="at8-mlucp-hc-btn" href="%s#respond">%s</a>',
                    esc_url(is_singular() ? get_permalink() : home_url('/')),
                    esc_html__('Post a Comment', 'at8-moonlight-user-center-pro')
                );
                $tip = esc_html__('Your comment must be approved before the content is revealed.', 'at8-moonlight-user-center-pro');
                break;
            default:
                $action = '';
                $tip = '';
        }

        return sprintf(
            '<div class="at8-mlucp-hidecontent at8-mlucp-hc-%s" data-type="%s">'
                . '<div class="at8-mlucp-hc-inner">'
                . '<span class="at8-mlucp-hc-icon" aria-hidden="true">%s</span>'
                . '<div class="at8-mlucp-hc-text">'
                . '<div class="at8-mlucp-hc-title">%s</div>'
                . '<div class="at8-mlucp-hc-tip">%s</div>'
                . '</div>'
                . '<div class="at8-mlucp-hc-action">%s</div>'
                . '</div></div>',
            $type_esc,
            $type_esc,
            $icon,
            esc_html($title),
            $tip,
            $action
        );
    }
}
