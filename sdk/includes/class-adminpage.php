<?php
/**
 * 统一授权后台页（规划书 §34/§35）。多产品并存：按 product 隔离配置。
 *
 * 两种用法：
 *   1. config['menu'] = ['parent' => ..., 'page_title' => ..., 'menu_title' => ...]
 *      → SDK 自动注册子菜单页；
 *   2. 插件在自己的页面里调用 AT8SDK_AdminPage::render_page() 嵌入。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('AT8SDK_AdminPage')) {

    class AT8SDK_AdminPage
{
    /** @var array<string, AT8SDK_Config> product => config */
    private static $configs = [];

    /** @var bool */
    private static $handle_registered = false;

    /** 操作授权能力（整改 V3 §四十二：Subscriber 不能激活/解绑/转移） */
    const CAP = 'manage_options';

    public static function init($config)
    {
        self::$configs[$config->product] = $config;

        if (!self::$handle_registered) {
            self::$handle_registered = true;
            add_action('admin_init', [__CLASS__, 'handle']);
        }

        if (is_array($config->menu) && !empty($config->menu['parent'])) {
            add_action('admin_menu', function () use ($config) {
                add_submenu_page(
                    $config->menu['parent'],
                    isset($config->menu['page_title']) ? $config->menu['page_title'] : '授权管理',
                    isset($config->menu['menu_title']) ? $config->menu['menu_title'] : '授权管理',
                    self::CAP,
                    $config->menu_slug,
                    function () use ($config) {
                        self::render($config);
                    }
                );
            });
        }
    }

    /** 表单处理：activate / deactivate / transfer / verify（按提交的 product 路由） */
    public static function handle()
    {
        if (empty($_POST['at8sdk_action']) || empty($_POST['at8sdk_product'])) {
            return;
        }

        $product = sanitize_key(wp_unslash($_POST['at8sdk_product']));
        $config = isset(self::$configs[$product]) ? self::$configs[$product] : null;

        // 授权检查先于 CSRF 检查：能力不足时直接静默返回，不泄漏表单是否存在
        if (!$config || !current_user_can(self::CAP)) {
            return;
        }
        check_admin_referer('at8sdk_' . $product, 'at8sdk_nonce');

        $license = AT8_License_SDK::license($product);
        if (!$license) {
            return;
        }

        $action = sanitize_key(wp_unslash($_POST['at8sdk_action']));
        $result = true;

        if ($action === 'activate') {
            $key = isset($_POST['at8sdk_key'])
                ? sanitize_text_field(wp_unslash($_POST['at8sdk_key']))
                : '';
            $result = $license->activate($key);
        } elseif ($action === 'deactivate') {
            $result = $license->deactivate();
            if (true === $result) {
                AT8SDK_Scheduler::unschedule($config);
            }
        } elseif ($action === 'transfer') {
            $to = isset($_POST['at8sdk_to_url'])
                ? esc_url_raw(wp_unslash($_POST['at8sdk_to_url']))
                : '';
            $result = $license->transfer($to);
        } elseif ($action === 'verify') {
            $license->verify();
            $result = true;
        }

        $is_error = is_string($result);
        $redirect = add_query_arg(
            ['at8sdk_msg' => $is_error ? 'error:' . rawurlencode($result) : $action],
            wp_get_referer() ? wp_get_referer() : admin_url('index.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

        /**
         * 兼容入口：渲染指定产品的授权页（嵌入模式）。
         * 多产品同站时必须传 $product；单产品站点可不传。
         */
        public static function render_page($product = '')
        {
            if ($product !== '' && isset(self::$configs[$product])) {
                self::render(self::$configs[$product]);
                return;
            }
            if (self::$configs) {
                self::render(end(self::$configs));
            }
        }

        /** 渲染指定产品的授权页 */
        public static function render($config)
        {
            // §四十二：页面本身也要能力校验。
            // 嵌入模式（render_page）绕过了 add_submenu_page 的能力控制，
            // 若不在此再挡一次，Subscriber 也能看到授权页并提交表单。
            if (!current_user_can(self::CAP)) {
                wp_die('您没有权限查看此页面。', '', ['response' => 403]);
            }

            $license = AT8_License_SDK::license($config->product);

            if (!$license) {
                echo '<div class="wrap"><h1>授权管理</h1><p>SDK 未初始化。</p></div>';
                return;
            }

            $state  = $license->state();
            $active = $license ? $license->is_valid() : false;
            $key    = $license ? $license->get_key() : '';
            $grace  = isset($state['status']) && $state['status'] === 'grace';
            ?>
            <div class="wrap">
                <h1>授权管理 — <?php echo esc_html($config->product); ?></h1>

                <?php // §二十二：配置层安全告警（如 http 明文、主题不支持自动更新） ?>
                <?php foreach ($config->warnings as $_warn) : ?>
                    <div class="notice notice-warning"><p><?php echo esc_html($_warn); ?></p></div>
                <?php endforeach; ?>

                <?php if (!empty($_GET['at8sdk_msg'])) :
                    $msg = sanitize_text_field(wp_unslash($_GET['at8sdk_msg']));
                    if (strpos($msg, 'error:') === 0) : ?>
                        <div class="notice notice-error"><p><?php echo esc_html(rawurldecode(substr($msg, 6))); ?></p></div>
                    <?php else : ?>
                        <div class="notice notice-success"><p>操作成功。</p></div>
                    <?php endif;
                endif; ?>

                <div style="background:#fff;border:1px solid #dcdcde;border-left:4px solid <?php echo $active ? '#00a32a' : '#b32d2e'; ?>;padding:14px 18px;max-width:680px;margin:16px 0">
                    <p style="font-size:15px;margin:0 0 6px">
                        <strong><?php echo $active ? '✓ ' . esc_html($license->status_label()) : '✕ ' . esc_html($license->status_label()); ?></strong>
                    </p>
                    <?php if ($key) : ?>
                        <p style="margin:0 0 4px;color:#646970">
                            <?php // §二十二：授权码脱敏显示，绝不输出完整 Key ?>
                            授权码：<code><?php echo esc_html(self::mask_key($key)); ?></code>
                        </p>
                        <p style="margin:0;color:#646970">
                            <?php if ($license->is_lifetime()) : ?>
                                有效期：终身
                            <?php elseif ($license->expires_at()) : ?>
                                到期时间：<?php echo esc_html(wp_date('Y-m-d', $license->expires_at())); ?>
                            <?php endif; ?>
                            <?php if (!empty($state['max_sites'])) : ?>
                                ｜站点额度：<?php echo esc_html($state['used_sites'] . '/' . $state['max_sites']); ?>
                            <?php endif; ?>
                            <?php if (!empty($state['domain'])) : ?>
                                ｜绑定域名：<?php echo esc_html($state['domain']); ?>
                            <?php endif; ?>
                        </p>
                        <?php // §二十二：只显示"已激活"，绝不显示 Activation Secret 明文 ?>
                        <p style="margin:6px 0 0;color:#646970">
                            站点凭证：<code><?php echo $license->get_secret() !== '' ? '已签发（不显示）' : '未签发'; ?></code>
                        </p>
                    <?php endif; ?>
                    <?php if ($license->last_error()) : ?>
                        <p style="margin:6px 0 0;color:#b32d2e"><?php echo esc_html($license->last_error()); ?></p>
                    <?php endif; ?>
                    <?php if ($grace) : ?>
                        <p style="margin:6px 0 0;color:#dba617">
                            宽限期截止：<?php echo esc_html(wp_date('Y-m-d H:i', (int) $state['grace_until'])); ?>，期间 Pro 功能正常可用。
                        </p>
                    <?php endif; ?>
                </div>

                <form method="post" style="background:#fff;border:1px solid #dcdcde;padding:16px;max-width:680px">
                    <?php wp_nonce_field('at8sdk_' . $config->product, 'at8sdk_nonce'); ?>
                    <input type="hidden" name="at8sdk_product" value="<?php echo esc_attr($config->product); ?>">
                    <input type="hidden" name="at8sdk_action" value="activate">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="at8sdk-key">授权码</label></th>
                            <td>
                                <input type="text" id="at8sdk-key" name="at8sdk_key" class="regular-text"
                                       value="" placeholder="AT8-XXXXX-XXXXX-XXXXX-XXXXX" autocomplete="off">
                                <p class="description">
                                    格式：AT8-XXXXX-XXXXX-XXXXX-XXXXX。
                                    <?php if ($config->buy_url) : ?>
                                        没有授权码？<a href="<?php echo esc_url($config->buy_url); ?>" target="_blank" rel="noopener">前往购买 →</a>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('激活授权', 'primary', 'submit', false); ?>
                </form>

                <?php if ($key) : ?>
                <p style="max-width:680px">
                    <form method="post" style="display:inline-block" onsubmit="return confirm('确认解绑本站？解绑后本站 Pro 功能停用。');">
                        <?php wp_nonce_field('at8sdk_' . $config->product, 'at8sdk_nonce'); ?>
                        <input type="hidden" name="at8sdk_product" value="<?php echo esc_attr($config->product); ?>">
                        <input type="hidden" name="at8sdk_action" value="deactivate">
                        <button class="button">解绑本站</button>
                    </form>
                    <form method="post" style="display:inline-block">
                        <?php wp_nonce_field('at8sdk_' . $config->product, 'at8sdk_nonce'); ?>
                        <input type="hidden" name="at8sdk_product" value="<?php echo esc_attr($config->product); ?>">
                        <input type="hidden" name="at8sdk_action" value="verify">
                        <button class="button">重新验证</button>
                    </form>
                </p>

                <?php // §三十九：域名变化必须走 Transfer，不能靠重新激活绕过 ?>
                <form method="post" style="background:#fff;border:1px solid #dcdcde;padding:16px;max-width:680px">
                    <h2 style="font-size:14px;margin:0 0 8px">域名转移</h2>
                    <?php wp_nonce_field('at8sdk_' . $config->product, 'at8sdk_nonce'); ?>
                    <input type="hidden" name="at8sdk_product" value="<?php echo esc_attr($config->product); ?>">
                    <input type="hidden" name="at8sdk_action" value="transfer">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="at8sdk-to-url">新站点 URL</label></th>
                            <td>
                                <input type="url" id="at8sdk-to-url" name="at8sdk_to_url" class="regular-text"
                                       value="" placeholder="https://new-site.com" autocomplete="off">
                                <p class="description">
                                    更换域名后请用此功能转移。直接在新域名重新激活会被服务端判定为域名未授权。
                                </p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('转移到新域名', 'secondary', 'submit', false, false); ?>
                </form>
                <?php endif; ?>
            </div>
            <?php
        }

        /**
         * 授权码脱敏：保留前缀与首尾各 5 位，中间固定为 ****。
         * §二十二：任何情况下页面都不输出完整授权码或 Activation Secret。
         */
        private static function mask_key($key)
        {
            $key = (string) $key;
            if (strlen($key) < 12) {
                return '********';
            }
            return substr($key, 0, 3) . '-****-****-****-' . substr($key, -5);
        }
    }
}
