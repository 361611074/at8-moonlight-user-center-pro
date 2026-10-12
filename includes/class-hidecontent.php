<?php
/**
 * Pro 隐藏内容扩展：会员 / 付费两型。
 *
 * 类型注册、权限判定与 CTA 渲染全部通过 Free 插件的扩展点注入
 * （at8_mluc_hide_types / at8_mluc_hide_user_can_view / at8_mluc_hide_cta_html），
 * 前台短码统一为 [at8_mluc_hide]。
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
                // 月费以上（含黄金/高级/钻石）即解锁
                return self::is_member_at_least('monthly');

            case 'payshow':
                // 会员月费以上 或 该文章付费墙已解锁
                if (self::is_member_at_least('monthly')) {
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

    /** 当前用户等级是否 >= $key（按 sort_order 比较）。 */
    private static function is_member_at_least($key)
    {
        if (!is_user_logged_in()) {
            return false;
        }
        if (!function_exists('at8_mlucp_user_level')) {
            return false;
        }
        $levels = method_exists('AT8_MLUC_Membership', 'get_levels')
            ? AT8_MLUC_Membership::get_levels()
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
}
