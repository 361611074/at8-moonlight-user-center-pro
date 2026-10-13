<?php
/**
 * PayPal 网关：Orders v2 REST API（免官方 SDK），前端 Smart Buttons + 服务端 capture。
 *
 * 流程：创建本地 pending 订单 → JS SDK createOrder（服务端创建 PayPal 订单）
 * → 用户批准 → 服务端 capture → 金额校验 → 开通会员。
 *
 * @package AT8_Moonlight_User_Center_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class AT8_MLUCP_PayPal
{
    /**
     * 是否启用（后台配置齐全才算启用）。
     */
    public static function enabled()
    {
        return (bool) at8_mlucp_get_option('paypal_enabled', 0)
            && '' !== trim((string) at8_mlucp_get_option('paypal_client_id', ''))
            && '' !== trim((string) at8_mlucp_get_option('paypal_secret', ''));
    }

    /**
     * API 基址。
     */
    public static function base()
    {
        return 'live' === at8_mlucp_get_option('paypal_mode', 'sandbox')
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * JS SDK 地址（购买卡片处直接输出 script）。
     */
    public static function sdk_url()
    {
        $args = array(
            'client-id' => trim((string) at8_mlucp_get_option('paypal_client_id', '')),
            'currency'  => AT8_MLUC_Payments::currency_code(),
            'intent'    => 'capture',
            'components' => 'buttons',
        );
        return 'https://www.paypal.com/sdk/js?' . build_query($args);
    }

    /**
     * OAuth2 token（transient 缓存，按 sandbox/live 分 key，提前 60s 失效）。
     */
    private static function get_token()
    {
        $mode  = 'live' === at8_mlucp_get_option('paypal_mode', 'sandbox') ? 'live' : 'sandbox';
        $cache = get_transient('at8_mlucp_pp_token_' . $mode);
        if ($cache) {
            return $cache;
        }
        $res = wp_remote_post(self::base() . '/v1/oauth2/token', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode(
                    trim((string) at8_mlucp_get_option('paypal_client_id', '')) . ':' .
                    trim((string) at8_mlucp_get_option('paypal_secret', ''))
                ),
            ),
            'body' => array('grant_type' => 'client_credentials'),
        ));
        if (is_wp_error($res)) {
            return $res;
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code >= 300 || empty($body['access_token'])) {
            return new WP_Error('at8_mlucp_pp_auth', __('PayPal 凭据校验失败，请检查 Client ID / Secret 与模式。', 'at8-moonlight-user-center-pro'));
        }
        $token = (string) $body['access_token'];
        $ttl   = isset($body['expires_in']) ? max(60, (int) $body['expires_in'] - 60) : 3000;
        set_transient('at8_mlucp_pp_token_' . $mode, $token, $ttl);
        return $token;
    }

    /**
     * 为本地订单创建 PayPal 订单。
     *
     * @return string|WP_Error PayPal order id
     */
    public static function create_order($order_id)
    {
        $order_id = (int) $order_id;
        $price    = (float) get_post_meta($order_id, '_at8_mluc_pay_price', true);
        $level    = (string) get_post_meta($order_id, '_at8_mluc_pay_level', true);
        if ($order_id <= 0 || $price <= 0) {
            return new WP_Error('at8_mlucp_pp_order', __('本地订单无效。', 'at8-moonlight-user-center-pro'));
        }

        // 商品名：付费墙订单用文章标题，会员订单用等级名。
        $item_label = (string) get_post_meta($order_id, '_at8_mluc_pay_title', true);
        if ('' === $item_label && class_exists('AT8_MLUC_Membership')) {
            $item_label = AT8_MLUC_Membership::get_level_label($level);
        }

        $token = self::get_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $res = wp_remote_post(self::base() . '/v2/checkout/orders', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'intent' => 'CAPTURE',
                'purchase_units' => array(array(
                    'custom_id'  => (string) $order_id,
                    'description' => sprintf(
                        '%s - %s',
                        get_bloginfo('name'),
                        $item_label
                    ),
                    'amount' => array(
                        'currency_code' => AT8_MLUC_Payments::currency_code(),
                        'value'         => AT8_MLUC_Payments::api_amount($price),
                    ),
                )),
            )),
        ));
        if (is_wp_error($res)) {
            return $res;
        }
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ((int) wp_remote_retrieve_response_code($res) >= 300 || empty($body['id'])) {
            $msg = isset($body['message']) ? $body['message'] : __('PayPal 订单创建失败。', 'at8-moonlight-user-center-pro');
            return new WP_Error('at8_mlucp_pp_create', $msg);
        }
        return (string) $body['id'];
    }

    /**
     * 服务端捕获 PayPal 订单，校验金额与本地订单一致。
     *
     * @return array|WP_Error array(capture_id, value)
     */
    public static function capture($pp_order_id)
    {
        $token = self::get_token();
        if (is_wp_error($token)) {
            return $token;
        }
        $pp_order_id = sanitize_text_field($pp_order_id);
        $res = wp_remote_post(self::base() . '/v2/checkout/orders/' . rawurlencode($pp_order_id) . '/capture', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body' => '{}',
        ));
        if (is_wp_error($res)) {
            return $res;
        }
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ((int) wp_remote_retrieve_response_code($res) >= 300
            || empty($body['status'])
            || 'COMPLETED' !== strtoupper((string) $body['status'])) {
            $msg = isset($body['message']) ? $body['message'] : __('PayPal 扣款未完成。', 'at8-moonlight-user-center-pro');
            return new WP_Error('at8_mlucp_pp_capture', $msg);
        }

        $unit  = isset($body['purchase_units'][0]) ? $body['purchase_units'][0] : array();
        $cap   = isset($unit['payments']['captures'][0]) ? $unit['payments']['captures'][0] : array();
        $value = isset($cap['amount']['value']) ? (float) $cap['amount']['value'] : 0;

        return array(
            'capture_id' => isset($cap['id']) ? (string) $cap['id'] : '',
            'custom_id'  => isset($unit['custom_id']) ? (string) $unit['custom_id'] : '',
            'value'      => $value,
        );
    }

    /**
     * AJAX：为本地订单创建 PayPal 订单（返回 PayPal order id 供 JS SDK）。
     */
    public static function ajax_create()
    {
        check_ajax_referer('at8_mlucp_nonce', 'nonce');
        if (!is_user_logged_in() || !self::enabled()) {
            at8_mlucp_send_json(false, __('PayPal 支付未启用。', 'at8-moonlight-user-center-pro'));
        }
        $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $err = self::check_order($order_id);
        if (is_wp_error($err)) {
            at8_mlucp_send_json(false, $err->get_error_message());
        }
        $pp_id = self::create_order($order_id);
        if (is_wp_error($pp_id)) {
            at8_mlucp_send_json(false, $pp_id->get_error_message());
        }
        update_post_meta($order_id, '_at8_mluc_pay_txn_ref', $pp_id);
        at8_mlucp_send_json(true, __('ok', 'at8-moonlight-user-center-pro'), array('pp_order_id' => $pp_id));
    }

    /**
     * AJAX：捕获并开通会员。
     */
    public static function ajax_capture()
    {
        check_ajax_referer('at8_mlucp_nonce', 'nonce');
        if (!is_user_logged_in() || !self::enabled()) {
            at8_mlucp_send_json(false, __('PayPal 支付未启用。', 'at8-moonlight-user-center-pro'));
        }
        $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $pp_id    = isset($_POST['pp_order_id']) ? sanitize_text_field(wp_unslash($_POST['pp_order_id'])) : '';
        $err = self::check_order($order_id);
        if (is_wp_error($err)) {
            at8_mlucp_send_json(false, $err->get_error_message());
        }

        $cap = self::capture($pp_id);
        if (is_wp_error($cap)) {
            at8_mlucp_send_json(false, $cap->get_error_message());
        }
        // 校验：PayPal 订单必须指向本地订单，金额一致。
        if ((string) $cap['custom_id'] !== (string) $order_id) {
            at8_mlucp_send_json(false, __('PayPal 订单与本地订单不匹配。', 'at8-moonlight-user-center-pro'));
        }
        $price = (float) get_post_meta($order_id, '_at8_mluc_pay_price', true);
        if (abs($cap['value'] - $price) > 0.01) {
            at8_mlucp_send_json(false, __('付款金额与订单金额不一致，请联系管理员核实。', 'at8-moonlight-user-center-pro'));
        }

        $done = AT8_MLUC_Payments::complete_order($order_id, $cap['capture_id'], 'paypal');
        if (is_wp_error($done)) {
            at8_mlucp_send_json(false, $done->get_error_message());
        }
        $ok_msg = ('paywall' === (string) get_post_meta($order_id, '_at8_mluc_pay_type', true))
            ? __('付款成功，内容已解锁。', 'at8-moonlight-user-center-pro')
            : __('付款成功，会员等级已开通。', 'at8-moonlight-user-center-pro');
        at8_mlucp_send_json(true, $ok_msg, array('reload' => 1));
    }

    /**
     * 校验本地订单归属 / 状态 / 网关。返回 WP_Error 或 true。
     */
    private static function check_order($order_id)
    {
        if ($order_id <= 0 || get_post_type($order_id) !== AT8_MLUC_Payments::CPT) {
            return new WP_Error('at8_mlucp_bad_order', __('订单不存在。', 'at8-moonlight-user-center-pro'));
        }
        if ((int) get_post_meta($order_id, '_at8_mluc_pay_user', true) !== get_current_user_id()) {
            return new WP_Error('at8_mlucp_bad_owner', __('无权操作该订单。', 'at8-moonlight-user-center-pro'));
        }
        if ('pending' !== (string) get_post_meta($order_id, '_at8_mluc_pay_status', true)) {
            return new WP_Error('at8_mlucp_bad_status', __('订单状态已变更，请刷新页面。', 'at8-moonlight-user-center-pro'));
        }
        if ('paypal' !== (string) get_post_meta($order_id, '_at8_mluc_pay_gateway', true)) {
            return new WP_Error('at8_mlucp_bad_gw', __('订单支付方式不是 PayPal。', 'at8-moonlight-user-center-pro'));
        }
        return true;
    }
}
