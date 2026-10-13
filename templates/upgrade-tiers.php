<?php
/**
 * 差价升级卡片（Pro tiered 路径）。
 *
 * 结构复用免费核心购买卡（.at8-mluc-buy / .at8-mluc-buy-grid /
 * .at8-mluc-btn-buy），按钮带 data-upgrade="1"，核心购买脚本会把它一并
 * POST 给 at8_mluc_buy_level，服务端按差价建单。
 *
 * @package AT8_Moonlight_User_Center_Pro
 *
 * @var array $args Template arguments.
 * @since 1.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$tier_levels = isset($args['levels']) && is_array($args['levels']) ? $args['levels'] : array();
$tier_user = isset($args['user_id']) ? (int) $args['user_id'] : 0;
$tier_current = isset($args['current']) ? (string) $args['current'] : '';
$tier_credit = isset($args['credit']) ? (float) $args['credit'] : 0;
$tier_gateways = isset($args['gateways']) && is_array($args['gateways']) ? $args['gateways'] : array();
$tier_symbol = isset($args['symbol']) ? (string) $args['symbol'] : '$';

if (empty($tier_levels)) {
    return;
}

$tier_fmt = static function ($v) use ($tier_symbol) {
    return $tier_symbol . number_format((float) $v, 2);
};
?>
<div class="at8-mluc-buy at8-mluc-tiered">
    <?php if ($tier_credit > 0) : ?>
        <p class="at8-mluc-tiered-note">
            <?php
            printf(
                /* translators: 1: current level name, 2: formatted amount already paid. */
                esc_html__('You hold %1$s. What you paid (%2$s) is credited in full when you move up.', 'at8-moonlight-user-center-pro'),
                '<strong>' . esc_html(AT8_MLUC_Membership::get_level_label($tier_current)) . '</strong>',
                '<strong>' . esc_html($tier_fmt($tier_credit)) . '</strong>'
            );
            ?>
        </p>
    <?php endif; ?>

    <div class="at8-mluc-buy-grid">
        <?php foreach ($tier_levels as $tier_key => $tier_lv) : ?>
            <?php
            $tier_price = isset($tier_lv['price']) ? (float) $tier_lv['price'] : 0;
            $tier_diff = $tier_price - $tier_credit;
            if ($tier_diff <= 0) {
                $tier_diff = $tier_price;
            }
            $tier_validity = isset($tier_lv['validity']) ? (int) $tier_lv['validity'] : 0;
            $tier_color = !empty($tier_lv['color']) ? $tier_lv['color'] : '#2f6fed';
            ?>
            <div class="at8-mluc-buy-card" style="border-top-color:<?php echo esc_attr($tier_color); ?>">
                <div class="at8-mluc-buy-name" style="color:<?php echo esc_attr($tier_color); ?>">
                    <?php echo esc_html(isset($tier_lv['label']) ? $tier_lv['label'] : $tier_key); ?>
                </div>
                <div class="at8-mluc-tiered-price">
                    <?php if ($tier_diff < $tier_price) : ?>
                        <span class="at8-mluc-tiered-origin"><?php echo esc_html($tier_fmt($tier_price)); ?></span>
                    <?php endif; ?>
                    <span class="at8-mluc-buy-price"><?php echo esc_html($tier_fmt($tier_diff)); ?></span>
                </div>
                <div class="at8-mluc-buy-validity">
                    <?php
                    echo $tier_validity > 0
                        ? esc_html(sprintf(/* translators: %d: number of days the membership level stays valid. */ __('Valid for %d days', 'at8-moonlight-user-center-pro'), $tier_validity))
                        : esc_html__('Permanent', 'at8-moonlight-user-center-pro');
                    ?>
                </div>
                <?php if ($tier_gateways) : ?>
                    <div class="at8-mluc-buy-actions">
                        <?php $tier_first = array_key_first($tier_gateways); ?>
                        <?php foreach ($tier_gateways as $tier_gid => $tier_gtitle) : ?>
                            <label class="at8-mluc-buy-gateway">
                                <input type="radio" name="at8_mluc_buy_gateway_<?php echo esc_attr($tier_key); ?>" value="<?php echo esc_attr($tier_gid); ?>" <?php checked($tier_gid, $tier_first); ?>>
                                <span><?php echo esc_html($tier_gtitle); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <span class="at8-mluc-buy-gw-empty"><?php esc_html_e('No payment method is available. Please contact the administrator.', 'at8-moonlight-user-center-pro'); ?></span>
                <?php endif; ?>
                <button type="button" class="at8-mluc-btn-buy" data-level="<?php echo esc_attr($tier_key); ?>" data-upgrade="1"<?php echo $tier_gateways ? '' : ' disabled'; ?>>
                    <?php esc_html_e('Upgrade', 'at8-moonlight-user-center-pro'); ?>
                </button>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="at8-mluc-buy-msg" role="alert"></p>
    <div class="at8-mluc-pp-mount" hidden aria-label="PayPal"></div>
</div>
