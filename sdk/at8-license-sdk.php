<?php
/**
 * AT8 License SDK — 统一授权客户端（单一来源，构建产物嵌入各 Pro 插件）。
 *
 * 插件侧接入（5 行）：
 *   require_once __DIR__ . '/sdk/at8-license-sdk.php';
 *   AT8_License_SDK::init([
 *       'server_url'   => 'https://www.at8.fun',
 *       'product'      => 'at8-security-pro',
 *       'product_type' => 'plugin',        // plugin（默认，支持自动更新）| theme（不支持）
 *       'plugin_file'  => __FILE__,
 *   ]);
 *
 *   if (AT8_License_SDK::license()->is_valid()) { boot_pro(); }
 *
 * 多产品并存：同一站点可激活任意多个 AT8 Pro 产品——类文件全部带
 * class_exists 守卫（只加载第一份副本），实例按 product 隔离。
 *
 * 设计要点（整改 V3 §九/§十/§十八/§十九/§三十一）：
 *   - is_valid() 只读本地状态，永不发 HTTP；
 *   - 本地状态带 HMAC 签名，改数据库无法伪造 active（§二十）；
 *   - 定期复查走 WP-Cron（每日），页面加载零请求；
 *   - 网络故障进入宽限期，且与「授权无效」严格区分（§十八 状态矩阵）；
 *   - 宽限期从上次成功验证起算，断网不能续命（§十九）；
 *   - revoked / expired / cancelled 立即停 Pro，数据保留；
 *   - 响应判定严格：HTTP 2xx + success===true + data 为数组 + schema 校验，
 *     WAF/CDN 返回的 200 错误页不会被误判为授权成功（§九/§十）。
 *
 * 更新能力边界（整改 V3 §三/§四）：
 *   插件（product_type=plugin）→ 支持 SDK 托管的自动更新；
 *   主题（product_type=theme）  → **不支持**。主题的 Theme Update API 与
 *   插件机制不同，只挂过滤器不实现安装器会造成权限错配。
 *   AT8SDK_Config 在构造时就把主题的 auto_update 强制为 false，
 *   并在后台显示"请通过下载中心或用户中心获取新版本"。
 *
 * v2.0.0 破坏性变更：签名协议升级为 AT8-SIG-V1（7 行规范化串），
 * 必须配套 at8-license-server v1.0.0 及以上版本。旧 SDK 的签名
 * 无法通过新服务端校验，两者不可混用。
 *
 * v2.0.1：主题更新边界、响应 schema 校验、Transfer 席位语义、
 *          http 明文拦截、宽限期防续命（详见 CHANGELOG）。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('AT8SDK_VERSION')) {
    define('AT8SDK_VERSION', '2.0.1');
}

if (!class_exists('AT8SDK_Config')) {
    require_once __DIR__ . '/includes/class-config.php';
}
if (!class_exists('AT8SDK_Response')) {
    require_once __DIR__ . '/includes/class-response.php';
}
if (!class_exists('AT8SDK_Domain')) {
    require_once __DIR__ . '/includes/class-domain.php';
}
if (!class_exists('AT8SDK_Client')) {
    require_once __DIR__ . '/includes/class-client.php';
}
if (!class_exists('AT8SDK_License')) {
    require_once __DIR__ . '/includes/class-license.php';
}
if (!class_exists('AT8SDK_Scheduler')) {
    require_once __DIR__ . '/includes/class-scheduler.php';
}
if (!class_exists('AT8SDK_AdminPage')) {
    require_once __DIR__ . '/includes/class-adminpage.php';
}
if (!class_exists('AT8SDK_Updater')) {
    require_once __DIR__ . '/includes/class-updater.php';
}

if (!class_exists('AT8_License_SDK')) {

    class AT8_License_SDK
    {
        /** @var array<string, AT8SDK_License> product => instance */
        private static $licenses = [];

        /**
         * 初始化本产品的授权实例（幂等；多产品互不干扰）。
         *
         * @return AT8SDK_License|null
         */
        public static function init($config)
        {
            $config_obj = new AT8SDK_Config($config);

            if ($config_obj->product === '') {
                return null;
            }
            if (isset(self::$licenses[$config_obj->product])) {
                return self::$licenses[$config_obj->product];
            }

            $client = new AT8SDK_Client($config_obj);
            $license = new AT8SDK_License($config_obj, $client);
            self::$licenses[$config_obj->product] = $license;

            AT8SDK_Scheduler::init($config_obj);
            AT8SDK_AdminPage::init($config_obj);

            // §四：只有插件产品才进入自动更新链路；
            // 主题产品的 auto_update 已在 Config 构造时强制为 false。
            if (!empty($config['update']) && $config_obj->supports_auto_update()) {
                AT8SDK_Updater::init($config_obj);
            }

            return $license;
        }

        /**
         * 取授权实例。
         *
         * @param string $product 产品 slug；空 = 返回本站第一个已初始化的产品。
         * @return AT8SDK_License|null
         */
        public static function license($product = '')
        {
            if ($product !== '') {
                return isset(self::$licenses[$product]) ? self::$licenses[$product] : null;
            }
            return self::$licenses ? reset(self::$licenses) : null;
        }
    }
}
