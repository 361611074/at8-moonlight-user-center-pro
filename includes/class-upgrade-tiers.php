<?php
/**
 * 差价升级路径（用户中心 Pro）。
 *
 * 在免费核心的升级路径注册表（at8_mluc_upgrade_paths）里注册 tiered 路径：
 * 会员从低等级升到高等级时，只需支付「目标等级价 − 当前等级价」的差价。
 * 下单仍走免费核心的 at8_mluc_buy_level（卡片按钮带 data-upgrade 标记，
 * at8_mluc_order_price 过滤器返回差价），原子认领与等级交货全部由核心负责，
 * Pro 不碰订单状态机。
 *
 * @package AT8_Moonlight_User_Center_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class AT8_MLUCP_Upgrade_Tiers
{
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
        add_filter('at8_mluc_upgrade_paths', array($this, 'register_path'));
        add_filter('at8_mluc_order_price', array($this, 'filter_order_price'), 10, 4);
    }

    /**
     * 注册 tiered 路径（卡片由 render_card 渲染）。
     */
    public function register_path($paths)
    {
        $paths['tiered'] = array(
            'label'  => at8_mlucp_ui_label('path_tiered', __('Pay the difference to upgrade', 'at8-moonlight-user-center-pro')),
            'usable' => array(__CLASS__, 'usable'),
            'render' => array(__CLASS__, 'render_card'),
        );
        return $paths;
    }

    /**
     * 有标价的等级才走差价路径。
     */
    public static function usable($level)
    {
        return isset($level['price']) && (float) $level['price'] > 0;
    }

    /**
     * at8_mluc_order_price：升级订单按差价计（仅带升级标记的请求）。
     * 差价 ≤ 0（目标更便宜或已付够）时退回原价，避免 0 元订单。
     */
    public function filter_order_price($price, $level, $user_id, $upgrade)
    {
        if (empty($upgrade) || !class_exists('AT8_MLUC_Membership')) {
            return $price;
        }
        $levels = AT8_MLUC_Membership::get_levels();
        $current = (string) AT8_MLUC_Membership::get_user_level($user_id);
        if ('' === $current || 'free' === $current || !isset($levels[$current])) {
            return $price;
        }
        if (AT8_MLUC_Membership::get_level_sort_order($current) >= AT8_MLUC_Membership::get_level_sort_order($level)) {
            return $price;
        }
        $credit = isset($levels[$current]['price']) ? (float) $levels[$current]['price'] : 0;
        $diff = (float) $price - $credit;
        return $diff > 0 ? $diff : $price;
    }

    /**
     * 渲染差价卡片：每个目标等级展示原价、抵扣与应付差价。
     * 按钮结构复用免费核心的购买脚本（.at8-mluc-buy / .at8-mluc-btn-buy）。
     *
     * @param array $levels  可升级到的等级（注册表已过滤）。
     * @param int   $user_id 当前用户。
     */
    public static function render_card($levels, $user_id)
    {
        if (!class_exists('AT8_MLUC_Payments') || !class_exists('AT8_MLUC_Membership')) {
            return;
        }
        $user_id = (int) $user_id;
        $current = (string) AT8_MLUC_Membership::get_user_level($user_id);
        $credit = 0;
        if ('' !== $current && 'free' !== $current) {
            $all = AT8_MLUC_Membership::get_levels();
            $credit = isset($all[$current]['price']) ? (float) $all[$current]['price'] : 0;
        }
        $args = array(
            'levels'   => is_array($levels) ? $levels : array(),
            'user_id'  => $user_id,
            'current'  => $current,
            'credit'   => $credit,
            'gateways' => AT8_MLUC_Payments::get_gateways(),
            'symbol'   => AT8_MLUC_Payments::get_currency_symbol(),
            'nonce'    => wp_create_nonce('at8_mluc_nonce'),
        );
        $args = (array) apply_filters('at8_mluc_template_args', $args, 'mlucp-upgrade-tiers');
        AT8_MLUC_Payments::enqueue_purchase_script($args);
        at8_mlucp_get_template('upgrade-tiers', $args);
    }
}
