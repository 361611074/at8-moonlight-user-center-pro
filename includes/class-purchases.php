<?php
/**
 * 购买记录：会员订单与付费墙解锁列表。
 *
 * 通过 Free 的 at8_mluc_account_tabs 过滤器自动挂「购买记录」tab；
 * 也可用 [at8_mlucp_purchases] 短代码独立使用。
 *
 * @package AT8_Moonlight_User_Center_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class AT8_MLUCP_Purchases
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
        add_shortcode('at8_mlucp_purchases', array($this, 'shortcode_purchases'));
        add_filter('at8_mluc_account_tabs', array($this, 'register_tab'));
    }

    /**
     * 注册「购买记录」tab（挂在 Free 账户中心）。
     */
    public function register_tab($tabs)
    {
        $tabs['purchases'] = array(
            'title'    => at8_mlucp_ui_label('tab_purchases', __('Purchases', 'at8-moonlight-user-center-pro')),
            'icon'     => 'dashicons-cart',
            'callback' => array($this, 'tab_purchases'),
        );
        return $tabs;
    }

    public function tab_purchases()
    {
        echo $this->shortcode_purchases();
    }

    /**
     * 查询当前用户的订单（会员购买 + 付费墙解锁，按时间倒序）。
     */
    public static function get_user_purchases($user_id = 0)
    {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id || !post_type_exists('at8_mluc_order')) {
            return array();
        }
        $orders = get_posts(array(
            'post_type'      => 'at8_mluc_order',
            'post_status'    => 'publish',
            'author'         => $user_id,
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ));
        $out = array();
        foreach ($orders as $oid) {
            $out[] = array(
                'id'     => (int) $oid,
                'title'  => get_the_title($oid),
                'level'  => (string) get_post_meta($oid, '_at8_mluc_pay_level', true),
                'post'   => (int) get_post_meta($oid, '_at8_mluc_pay_post', true),
                'type'   => (string) get_post_meta($oid, '_at8_mluc_pay_type', true),
                'price'  => (float) get_post_meta($oid, '_at8_mluc_pay_price', true),
                'status' => (string) get_post_meta($oid, '_at8_mluc_pay_status', true),
                'date'   => (int) get_post_time('U', true, $oid),
            );
        }
        return $out;
    }

    /**
     * 短代码 [at8_mlucp_purchases] 列出当前用户订单。
     */
    public function shortcode_purchases()
    {
        if (!is_user_logged_in()) {
            return '<p class="at8-mlucp-message">' . esc_html(at8_mlucp_ui_label('purchases_login', __('Please log in first to view your purchases.', 'at8-moonlight-user-center-pro'))) . '</p>';
        }
        $purchases = self::get_user_purchases();

        ob_start();
        echo '<div class="at8-mlucp-purchases">';
        if (!empty($purchases)) {
            echo '<div class="at8-mlucp-table-scroll"><table class="at8-mlucp-table"><thead><tr>';
            echo '<th>' . esc_html(at8_mlucp_ui_label('pu_th_item', __('Item', 'at8-moonlight-user-center-pro'))) . '</th>';
            echo '<th>' . esc_html(at8_mlucp_ui_label('pu_th_amount', __('Amount', 'at8-moonlight-user-center-pro'))) . '</th>';
            echo '<th>' . esc_html(at8_mlucp_ui_label('pu_th_status', __('Status', 'at8-moonlight-user-center-pro'))) . '</th>';
            echo '<th>' . esc_html(at8_mlucp_ui_label('pu_th_date', __('Date', 'at8-moonlight-user-center-pro'))) . '</th>';
            echo '</tr></thead><tbody>';
            $symbol = AT8_MLUC_Payments::get_currency_symbol();
            $status_labels = array(
                'pending'   => __('Pending', 'at8-moonlight-user-center-pro'),
                'paid'      => __('Paid', 'at8-moonlight-user-center-pro'),
                'cancelled' => __('Cancelled', 'at8-moonlight-user-center-pro'),
            );
            foreach ($purchases as $p) {
                echo '<tr>';
                echo '<td>' . esc_html($p['title']) . '</td>';
                echo '<td>' . esc_html($symbol . number_format($p['price'], 2)) . '</td>';
                echo '<td>' . esc_html(isset($status_labels[$p['status']]) ? $status_labels[$p['status']] : $p['status']) . '</td>';
                echo '<td>' . esc_html(date_i18n(get_option('date_format', 'Y-m-d'), $p['date'])) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<p class="at8-mlucp-empty">' . esc_html(at8_mlucp_ui_label('purchases_empty', __('You have no purchases yet.', 'at8-moonlight-user-center-pro'))) . '</p>';
        }
        echo '</div>';
        return ob_get_clean();
    }
}
