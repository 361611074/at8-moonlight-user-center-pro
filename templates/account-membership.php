<?php
/**
 * 會員等級 Tab：当前等级 + 等级对照（升级块由 class-account.php 统一包入 .at8-mlucp-card 渲染）。
 *
 * @var int    $user_id
 * @var string $level
 * @var int    $expires
 * @var bool   $expired
 * @var array  $levels
 *
 * @package AT8_Moonlight_User_Center_Pro
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="at8-mlucp-membership">

    <section class="at8-mlucp-card" aria-label="<?php echo esc_attr(at8_mlucp_ui_label('mb_current_title', __('Current Membership', 'at8-moonlight-user-center-pro'))); ?>">
        <h3 class="at8-mlucp-card-title"><?php echo esc_html(at8_mlucp_ui_label('mb_current_title', __('Current Membership', 'at8-moonlight-user-center-pro'))); ?></h3>
        <p class="at8-mlucp-membership-level">
            <span class="at8-mlucp-level-badge at8-mlucp-level-<?php echo esc_attr($level); ?>">
                <?php
                echo esc_html('' !== $level
                    ? AT8_MLUCP_Membership::get_level_label($level)
                    : at8_mlucp_ui_label('no_member', __('Non-member', 'at8-moonlight-user-center-pro')));
                ?>
            </span>
        </p>
        <?php if ('' === $level || 'free' === $level) : ?>
            <p class="at8-mlucp-membership-tip">
                <?php if ('free' === $level) : ?>
                    <?php echo esc_html(at8_mlucp_ui_label('mb_free_tip', __('You are currently a Free member. Upgrade to Monthly or Premium to unlock more materials and demo videos.', 'at8-moonlight-user-center-pro'))); ?>
                <?php else : ?>
                    <?php echo esc_html(at8_mlucp_ui_label('mb_none_tip', __('You do not have a membership yet. Upgrade to unlock more materials and demo videos.', 'at8-moonlight-user-center-pro'))); ?>
                <?php endif; ?>
            </p>
        <?php else : ?>
            <p class="at8-mlucp-membership-tip">
                <?php if ($expires) : ?>
                    <?php if ($expired) : ?>
                        <?php echo esc_html(at8_mlucp_ui_label('mb_expired_tip', __('Your membership has expired. Please renew to continue.', 'at8-moonlight-user-center-pro'))); ?>
                    <?php else : ?>
                        <?php
                        $expire_str = date_i18n(get_option('date_format', 'Y-m-d'), $expires);
                        echo esc_html(
                            sprintf(
                                /* translators: %s: membership expiry date, localized. */
                                at8_mlucp_ui_label('mb_expire_date', __('Expires: %s', 'at8-moonlight-user-center-pro')),
                                $expire_str
                            )
                        );
                        ?>
                    <?php endif; ?>
                <?php else : ?>
                    <?php echo esc_html(at8_mlucp_ui_label('mb_permanent', __('Lifetime member', 'at8-moonlight-user-center-pro'))); ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </section>

    <section class="at8-mlucp-card" aria-label="<?php echo esc_attr(at8_mlucp_ui_label('mb_table_title', __('Membership Levels', 'at8-moonlight-user-center-pro'))); ?>">
        <h3 class="at8-mlucp-card-title"><?php echo esc_html(at8_mlucp_ui_label('mb_table_title', __('Membership Levels', 'at8-moonlight-user-center-pro'))); ?></h3>
        <p class="at8-mlucp-card-subtitle">
            <?php echo esc_html(at8_mlucp_ui_label('mb_table_sub', __('Compare the benefits and your current status of each level.', 'at8-moonlight-user-center-pro'))); ?>
        </p>
        <div class="at8-mlucp-table-scroll">
            <table class="at8-mlucp-table">
                <thead>
                    <tr>
                        <th><?php echo esc_html(at8_mlucp_ui_label('th_level', __('Level', 'at8-moonlight-user-center-pro'))); ?></th>
                        <th><?php echo esc_html(at8_mlucp_ui_label('mb_th_desc', __('Description', 'at8-moonlight-user-center-pro'))); ?></th>
                        <th><?php echo esc_html(at8_mlucp_ui_label('th_status', __('Status', 'at8-moonlight-user-center-pro'))); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($levels as $key => $lv) :
                        $description = isset($lv['description']) && $lv['description'] !== ''
                            ? $lv['description']
                            : __('Browse basic content and purchase paid materials.', 'at8-moonlight-user-center-pro');
                        ?>
                        <tr class="<?php echo $key === $level ? 'is-current' : ''; ?>">
                            <td>
                                <span class="at8-mlucp-level-badge at8-mlucp-level-<?php echo esc_attr($key); ?>">
                                    <?php echo esc_html(AT8_MLUCP_Membership::get_level_label($key)); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($description); ?></td>
                            <td>
                                <?php if ($key === $level) : ?>
                                    <span class="at8-mlucp-level-current"><?php echo esc_html(at8_mlucp_ui_label('mb_st_current', __('Current', 'at8-moonlight-user-center-pro'))); ?></span>
                                <?php elseif (AT8_MLUCP_Membership::get_level_sort_order($key) < AT8_MLUCP_Membership::get_level_sort_order($level)) : ?>
                                    <span class="at8-mlucp-level-included"><?php echo esc_html(at8_mlucp_ui_label('mb_st_included', __('Included', 'at8-moonlight-user-center-pro'))); ?></span>
                                <?php else : ?>
                                    <span class="at8-mlucp-level-locked"><?php echo esc_html(at8_mlucp_ui_label('mb_st_locked', __('Locked', 'at8-moonlight-user-center-pro'))); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

</div>
