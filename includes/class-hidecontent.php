<?php
/**
 * Pro 隐藏内容扩展：会员 / 付费两型。
 *
 * 类型注册、权限判定与 CTA 渲染全部通过 Free 插件的扩展点注入
 * （at8_mluc_hide_types / at8_mluc_hide_user_can_view / at8_mluc_hide_cta_html），
 * 前台短码统一为 [at8_mluc_hide]。
 *
 * 1.5.0 起会员闸统一走免费核心的等级 API（AT8_MLUC_Membership::
 * user_can_access）：最低等级可在设置里改（选项 hide_min_level，默认
 * monthly），不再硬编码等级键、也不再自带一份 sort_order 兜底表；
 * 配置的等级被删时闸门保持关闭（拒绝），绝不退回"全员放行"。
 *
 * 兼容：1.0.x 的 [at8_mlucp_hide] 短码名与旧文章内容继续可用（别名 +
 * 旧内容过滤），无需批量替换。
 *
 * @package AT8_Moonlight_User_Center_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class AT8_MLUCP_Hidecontent
{
    /** Pro 专有类型。 */
    public static $types = array(
        'vip1'    => array('label' => '会员可查看',   'cta' => 'Members Only',       'icon' => '⭐'),
        'payshow' => array('label' => '付费后可查看', 'cta' => 'Purchase to View',   'icon' => '💎'),
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
        // Free 扩展点：注册类型、权限判定、CTA 渲染。
        add_filter('at8_mluc_hide_types', array($this, 'register_types'));
        add_filter('at8_mluc_hide_user_can_view', array($this, 'user_can_view'), 10, 2);
        add_filter('at8_mluc_hide_cta_html', array($this, 'render_cta_html'), 10, 2);
        // 会员闸最低等级设置（挂在 Free 设置屏的 Hidden Content 分区）。
        add_action('at8_mluc_register_settings', array($this, 'register_settings'));
    }

    /**
     * 向 Free 注册 Pro 类型。
     */
    public function register_types($types)
    {
        return array_merge((array) $types, self::$types);
    }

    /**
     * Pro 类型的查看权限。
     *
     * @param bool   $can_view Free 的默认判定（false）。
     * @param string $type    类型键。
     */
    public function user_can_view($can_view, $type)
    {
        switch ($type) {
            case 'vip1':
                // 达到会员闸最低等级即解锁
                return self::is_member_at_least();

            case 'payshow':
                // 会员闸以上 或 该文章付费墙已解锁
                if (self::is_member_at_least()) {
                    return true;
                }
                return self::is_post_paywall_unlocked();
        }
        return $can_view;
    }

    /**
     * Pro 类型的锁定卡（复用 Free 的卡片结构与样式类）。
     *
     * @param string $html Free 的默认输出（空）。
     * @param string $type 类型键。
     */
    public function render_cta_html($html, $type)
    {
        $info   = isset(self::$types[$type]) ? self::$types[$type] : null;
        if (!$info) {
            return $html;
        }
        $type_esc = esc_attr($type);

        $account_url = at8_mlucp_get_account_url();
        $login_url   = at8_mlucp_get_login_url(get_permalink());

        if ('vip1' === $type) {
            $action = sprintf(
                '<a class="at8-mluc-hc-btn" href="%s">%s</a>',
                esc_url(add_query_arg('tab', 'membership', $account_url)),
                esc_html__('Upgrade Membership', 'at8-moonlight-user-center-pro')
            );
            $tip = esc_html__('This content is for Monthly members and above.', 'at8-moonlight-user-center-pro');
        } else {
            // payshow：当前文章开了付费墙则跳文章触发解锁，否则跳会员购买
            $target = add_query_arg('tab', 'membership', $account_url);
            if (is_singular() && class_exists('AT8_MLUCP_Paywall') && AT8_MLUCP_Paywall::is_paywalled((int) get_queried_object_id())) {
                $target = get_permalink((int) get_queried_object_id());
            } elseif (!is_user_logged_in()) {
                $target = $login_url;
            }
            $action = sprintf(
                '<a class="at8-mluc-hc-btn" href="%s">%s</a>',
                esc_url($target),
                esc_html__('Buy Now', 'at8-moonlight-user-center-pro')
            );
            $tip = esc_html__('Purchase to view the content below.', 'at8-moonlight-user-center-pro');
        }

        // 标题走 Free 的 label 过滤器（键与 Free 内置类型同前缀，后台可统一覆盖）。
        $title = at8_mluc_ui_label(
            'hc_title_' . $type,
            isset($info['cta']) ? $info['cta'] : 'Locked Content'
        );

        return sprintf(
            '<div class="at8-mluc-hidecontent at8-mluc-hc-%1$s" data-type="%1$s">'
                . '<div class="at8-mluc-hc-inner">'
                . '<span class="at8-mluc-hc-icon" aria-hidden="true">%2$s</span>'
                . '<div class="at8-mluc-hc-text">'
                . '<div class="at8-mluc-hc-title">%3$s</div>'
                . '<div class="at8-mluc-hc-tip">%4$s</div>'
                . '</div>'
                . '<div class="at8-mluc-hc-action">%5$s</div>'
                . '</div></div>',
            $type_esc,
            esc_html($info['icon']),
            esc_html($title),
            $tip,
            $action
        );
    }

    /**
     * 会员闸最低等级（vip1 / payshow 共用）。
     *
     * 选项 hide_min_level（默认 monthly）+ at8_mlucp_hide_min_level 过滤器；
     * 只返回当前仍存在的等级，被删后返回 ''——闸门保持关闭。
     */
    public static function min_level()
    {
        $key = sanitize_key((string) at8_mlucp_get_option('hide_min_level', 'monthly'));
        if ('' === $key) {
            $key = 'monthly';
        }
        /**
         * Filters the minimum membership level the Pro hidden-content gate asks for.
         *
         * @since 1.5.0
         *
         * @param string $key Level slug.
         */
        $key = sanitize_key((string) apply_filters('at8_mlucp_hide_min_level', $key));
        $levels = class_exists('AT8_MLUC_Membership') ? AT8_MLUC_Membership::get_levels() : array();
        return isset($levels[$key]) ? $key : '';
    }

    /**
     * 当前用户是否达到会员闸最低等级。
     *
     * 判定统一委托免费核心（user_can_access：按 sort_order 比较），
     * 最低等级不存在时拒绝（历史上的硬编码 sort_order 兜底会误放行）。
     *
     * @param string $key 指定等级；留空用设置里的最低等级。
     */
    private static function is_member_at_least($key = '')
    {
        if (!is_user_logged_in() || !class_exists('AT8_MLUC_Membership')) {
            return false;
        }
        $key = $key ? sanitize_key($key) : self::min_level();
        if ('' === $key) {
            return false;
        }
        return AT8_MLUC_Membership::user_can_access($key, get_current_user_id());
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

    /* ---------- 设置：会员闸最低等级 ---------- */

    /**
     * 在 Free 设置屏注册 Hidden Content 分区。
     */
    public function register_settings()
    {
        if (!class_exists('AT8_MLUC_Settings')) {
            return;
        }
        $page = AT8_MLUC_Settings::PAGE;

        add_settings_section(
            'at8_mlucp_hidecontent',
            __('Hidden Content', 'at8-moonlight-user-center-pro'),
            array($this, 'section_hidecontent'),
            $page
        );

        add_settings_field(
            'at8_mlucp_hidecontent',
            __('Members-only gate', 'at8-moonlight-user-center-pro'),
            array($this, 'render_min_level_field'),
            $page,
            'at8_mlucp_hidecontent'
        );
    }

    /**
     * 分区说明。
     */
    public function section_hidecontent()
    {
        echo '<p class="description">' . esc_html__('The [at8_mluc_hide] shortcode covers logged-in readers, commenters and members. This sets the membership level the members-only and purchase-to-view types ask for.', 'at8-moonlight-user-center-pro') . '</p>';
    }

    /**
     * 渲染最低等级选择框。
     */
    public function render_min_level_field()
    {
        $levels = class_exists('AT8_MLUC_Membership') ? AT8_MLUC_Membership::get_levels() : array();
        $current = (string) at8_mlucp_get_option('hide_min_level', 'monthly');
        $valid = self::min_level();
        if ('' !== $current && '' === $valid) {
            printf('<p class="description">%s</p>', esc_html__('The configured level no longer exists, so the gate stays closed for everyone. Pick a level below.', 'at8-moonlight-user-center-pro'));
        }
        ?>
        <select id="at8_mlucp_hide_min_level" name="at8_mlucp_options[hide_min_level]">
            <?php foreach ($levels as $lk => $lv) : ?>
                <?php if ('free' === $lk) { continue; } ?>
                <option value="<?php echo esc_attr($lk); ?>" <?php selected($current, $lk); ?>>
                    <?php echo esc_html(isset($lv['label']) ? (string) $lv['label'] : $lk); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html__('Members of this level and above see members-only content without paying.', 'at8-moonlight-user-center-pro'); ?></p>
        <?php
    }
}
