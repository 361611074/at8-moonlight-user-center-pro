<?php
/**
 * 独立会员购买卡片（仅装用户中心、未启用商城时显示）。
 * 支持网关：线下转账（manual）/ PayPal（Smart Buttons）/ Stripe（Checkout 跳转）。
 *
 * @var array  $levels
 * @var array  $gateways
 * @var array  $orders
 * @var string $symbol
 * @var string $instructions
 * @var string $nonce
 * @var bool   $paypal_on
 * @var string $paypal_sdk
 * @var bool   $stripe_on
 * @var string $pay_notice
 *
 * @package AT8_Moonlight_User_Center_Pro
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="at8-mlucp-buy">
    <?php if ('paid' === $pay_notice) : ?>
        <p class="at8-mlucp-buy-notice at8-mlucp-buy-notice-ok"><?php echo esc_html(at8_mlucp_ui_label('buy_ok', __('Payment successful. Your membership has been activated.', 'at8-moonlight-user-center-pro'))); ?></p>
    <?php elseif ('failed' === $pay_notice) : ?>
        <p class="at8-mlucp-buy-notice at8-mlucp-buy-notice-err"><?php echo esc_html(at8_mlucp_ui_label('buy_failed', __('Payment was not completed or verification failed. Please try again or contact the administrator.', 'at8-moonlight-user-center-pro'))); ?></p>
    <?php elseif ('cancelled' === $pay_notice) : ?>
        <p class="at8-mlucp-buy-notice"><?php echo esc_html(at8_mlucp_ui_label('buy_cancelled', __('Payment cancelled. The order remains pending.', 'at8-moonlight-user-center-pro'))); ?></p>
    <?php endif; ?>

    <div class="at8-mlucp-buy-grid">
        <?php foreach ($levels as $key => $lv) :
            if ('free' === $key || '' === $key) {
                continue;
            }
            $price    = isset($lv['price']) ? (float) $lv['price'] : 0;
            $validity = isset($lv['validity']) ? (int) $lv['validity'] : 0;
            $color    = isset($lv['color']) ? $lv['color'] : '#2f6fed';
            ?>
            <div class="at8-mlucp-buy-card" style="border-top-color:<?php echo esc_attr($color); ?>">
                <div class="at8-mlucp-buy-name" style="color:<?php echo esc_attr($color); ?>"><?php echo esc_html($lv['label']); ?></div>
                <div class="at8-mlucp-buy-price"><?php echo esc_html($symbol . number_format($price, 2)); ?></div>
                <div class="at8-mlucp-buy-validity">
                    <?php echo $validity > 0
                        /* translators: %d: number of days the membership level stays valid. */
                        ? sprintf(at8_mlucp_ui_label('buy_validity_days', __('Valid for %d days', 'at8-moonlight-user-center-pro')), $validity)
                        : at8_mlucp_ui_label('permanent', __('Permanent', 'at8-moonlight-user-center-pro')); ?>
                </div>
                <?php if ($price > 0) : ?>
                    <div class="at8-mlucp-buy-actions">
                        <?php if ($gateways) :
                            $at8_mlucp_first_gw = array_key_first($gateways); ?>
                            <?php foreach ($gateways as $gid => $gtitle) : ?>
                                <label class="at8-mlucp-buy-gateway">
                                    <input type="radio" name="at8_mlucp_buy_gateway_<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($gid); ?>" <?php checked($gid, $at8_mlucp_first_gw); ?>>
                                    <span><?php echo esc_html($gtitle); ?></span>
                                </label>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <span class="at8-mlucp-buy-gw-empty"><?php echo esc_html(at8_mlucp_ui_label('buy_no_gateway', __('No payment method is available. Please contact the administrator.', 'at8-moonlight-user-center-pro'))); ?></span>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="at8-mlucp-btn-buy" data-level="<?php echo esc_attr($key); ?>"<?php echo $gateways ? '' : ' disabled'; ?>><?php echo esc_html(at8_mlucp_ui_label('buy_btn', __('Buy', 'at8-moonlight-user-center-pro'))); ?></button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="at8-mlucp-buy-msg" role="alert"></p>
    <div class="at8-mlucp-pp-mount" hidden aria-label="PayPal"></div>

    <?php if (isset($gateways['manual'])) : ?>
        <div class="at8-mlucp-buy-instructions">
            <strong><?php echo esc_html(at8_mlucp_ui_label('buy_instructions', __('Payment Instructions', 'at8-moonlight-user-center-pro'))); ?></strong>
            <p><?php echo esc_html($instructions); ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($orders)) : ?>
        <div class="at8-mlucp-order-list">
            <strong><?php echo esc_html(at8_mlucp_ui_label('buy_history', __('My Purchase History', 'at8-moonlight-user-center-pro'))); ?></strong>
            <table class="at8-mlucp-table">
                <thead>
                    <tr>
                        <th><?php echo esc_html(at8_mlucp_ui_label('buy_th_order', __('Order', 'at8-moonlight-user-center-pro'))); ?></th>
                        <th><?php echo esc_html(at8_mlucp_ui_label('th_level', __('Level', 'at8-moonlight-user-center-pro'))); ?></th>
                        <th><?php echo esc_html(at8_mlucp_ui_label('buy_th_amount', __('Amount', 'at8-moonlight-user-center-pro'))); ?></th>
                        <th><?php echo esc_html(at8_mlucp_ui_label('th_status', __('Status', 'at8-moonlight-user-center-pro'))); ?></th>
                        <th><?php echo esc_html(at8_mlucp_ui_label('buy_th_date', __('Date', 'at8-moonlight-user-center-pro'))); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $o) :
                        $status_labels = array(
                            'pending'   => at8_mlucp_ui_label('buy_st_pending', __('Pending', 'at8-moonlight-user-center-pro')),
                            'paid'      => at8_mlucp_ui_label('buy_st_paid', __('Paid', 'at8-moonlight-user-center-pro')),
                            'cancelled' => at8_mlucp_ui_label('buy_st_cancelled', __('Cancelled', 'at8-moonlight-user-center-pro')),
                        );
                        $st = isset($status_labels[$o['status']]) ? $status_labels[$o['status']] : $o['status'];
                        ?>
                        <tr>
                            <td><?php echo esc_html($o['title']); ?></td>
                            <td><?php echo esc_html(AT8_MLUCP_Membership::get_level_label($o['level'])); ?></td>
                            <td><?php echo esc_html($symbol . number_format($o['price'], 2)); ?></td>
                            <td><span class="at8-mlucp-pill at8-mlucp-pill-<?php echo esc_attr($o['status']); ?>"><?php echo esc_html($st); ?></span></td>
                            <td><?php echo esc_html($o['date']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php if ($paypal_on && $paypal_sdk) : ?>
    <script src="<?php echo esc_url($paypal_sdk); ?>" data-partner-attribution-id="moonlight_user_center"></script>
<?php endif; ?>
<script>
(function () {
    var root = document.querySelector('.at8-mlucp-buy');
    if (!root) { return; }
    var msg = root.querySelector('.at8-mlucp-buy-msg');
    var ppMount = root.querySelector('.at8-mlucp-pp-mount');
    var ppOrder = null;

    function say(text) { if (msg) { msg.textContent = text || ''; } }

    function post(action, extra) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', '<?php echo esc_js($nonce); ?>');
        if (extra) { Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); }); }
        return fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }

    function mountPayPal(orderId) {
        if (!window.paypal || !ppMount) {
            say('<?php echo esc_js(at8_mlucp_ui_label('buy_pp_load_fail', __('Failed to load PayPal. Please refresh the page and try again.', 'at8-moonlight-user-center-pro'))); ?>');
            return;
        }
        ppOrder = orderId;
        ppMount.hidden = false;
        ppMount.innerHTML = '';
        window.paypal.Buttons({
            style: { layout: 'vertical', label: 'paypal' },
            createOrder: function () {
                return post('at8_mlucp_paypal_create', { order_id: orderId }).then(function (res) {
                    if (!res.success) { throw new Error(res.message || 'create failed'); }
                    return res.data.pp_order_id;
                });
            },
            onApprove: function () {
                say('<?php echo esc_js(at8_mlucp_ui_label('buy_pp_confirming', __('Confirming payment...', 'at8-moonlight-user-center-pro'))); ?>');
                return post('at8_mlucp_paypal_capture', { order_id: orderId, pp_order_id: ppOrder }).then(function (res) {
                    say(res.message);
                    if (res.success) { setTimeout(function () { window.location.reload(); }, 1500); }
                });
            },
            onError: function () {
                say('<?php echo esc_js(at8_mlucp_ui_label('buy_pp_error', __('PayPal payment error. Please try again or contact the administrator.', 'at8-moonlight-user-center-pro'))); ?>');
            }
        }).render(ppMount);
    }

    root.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.at8-mlucp-btn-buy') : null;
        if (!btn) { return; }
        e.preventDefault();
        var level = btn.getAttribute('data-level');
        var gwEl = root.querySelector('input[name="at8_mlucp_buy_gateway_' + level + '"]:checked');
        var gw = gwEl ? gwEl.value : '';
        say('');
        btn.disabled = true;
        post('at8_mlucp_buy_level', { level: level, gateway: gw })
            .then(function (res) {
                if (!res.success) { say(res.message); return; }
                var flow = res.data && res.data.flow;
                if ('paypal' === flow) {
                    say('<?php echo esc_js(at8_mlucp_ui_label('buy_pp_goto', __('Please complete the PayPal payment below.', 'at8-moonlight-user-center-pro'))); ?>');
                    mountPayPal(res.data.order_id);
                } else if ('stripe' === flow && res.data.redirect) {
                    window.location.href = res.data.redirect;
                } else {
                    say(res.message);
                }
            })
            .catch(function () {
                say('<?php echo esc_js(at8_mlucp_ui_label('buy_net_error', __('Network error. Please try again later.', 'at8-moonlight-user-center-pro'))); ?>');
            })
            .finally(function () { btn.disabled = false; });
    });
})();
</script>
