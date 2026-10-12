<?php
/**
 * SDK 配置。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('AT8SDK_Config')) {

class AT8SDK_Config
{
    /** 支持的产品类型 → 对应的 WordPress 更新能力与过滤器 */
    const TYPES = [
        'plugin' => ['cap' => 'update_plugins', 'filter' => 'pre_set_site_transient_update_plugins'],
        'theme'  => ['cap' => 'update_themes',  'filter' => 'pre_set_site_transient_update_themes'],
    ];

    /** @var string 授权服务器地址，如 https://www.at8.fun */
    public $server_url;

    /** @var string 产品 slug（与授权中心产品一致，如 at8-security-pro） */
    public $product;

    /** @var string 产品类型：plugin（默认，支持自动更新）| theme（不支持自动更新） */
    public $product_type;

    /** @var bool 是否启用 SDK 托管的授权制自动更新 */
    public $auto_update;

    /** @var string Pro 插件主文件 */
    public $plugin_file;

    /** @var string 购买链接 */
    public $buy_url;

    /** @var array 授权页挂载：parent（WP admin 页面 slug）+ page_title/menu_title；
     *             传 parent=null 则插件自渲染（用 render_page() 嵌入自己的页面） */
    public $menu;

    /** @var string 文本域 */
    public $text_domain;

    /** @var string 自动注册页面的页面 slug（menu 配置了 parent 时使用） */
    public $menu_slug;

    /** @var string[] 配置警告（后台页面展示） */
    public $warnings = [];

    public function __construct($config)
    {
        $c = array_merge([
            'server_url'   => 'https://www.at8.fun',
            'product'      => '',
            'product_type' => 'plugin',
            'auto_update'  => true,
            'plugin_file'  => '',
            'buy_url'      => '',
            'menu'         => null,
            'menu_slug'    => '',
            'text_domain'  => 'at8-license-sdk',
        ], is_array($config) ? $config : []);

        // 常量覆盖（wp-config.php 可硬编码，防止被篡改指向假服务器）
        if (defined('AT8SDK_LICENSE_SERVER') && AT8SDK_LICENSE_SERVER) {
            $c['server_url'] = AT8SDK_LICENSE_SERVER;
        }

        $this->server_url = untrailingslashit((string) $c['server_url']);
        $this->product    = (string) $c['product'];

        // ---- product_type 归一化（整改 V3 §三/§四）----
        // 未知类型一律降级为 plugin，绝不猜测
        $type = strtolower(trim((string) $c['product_type']));
        if ($type === '') {
            $type = 'plugin';
        }
        if (!isset(self::TYPES[$type])) {
            $this->warnings[] = sprintf(
                'product_type「%s」不是受支持的类型（plugin / theme），已按 plugin 处理。',
                $type
            );
            $type = 'plugin';
        }
        $this->product_type = $type;
        $this->auto_update  = (bool) $c['auto_update'];

        // §四：主题产品的自动更新默认关闭。SDK v2 只正式支持插件自动更新——
        // 主题的 Theme Update API（theme basename / stylesheet / update_themes
        // 安装器）与插件机制不同，若只挂过滤器而不实现对应安装器，
        // 会出现「权限错配」：能触发更新却装不上，或装上了却越权。
        if ($type === 'theme' && $this->auto_update) {
            $this->auto_update = false;
            $this->warnings[]  = '主题产品不支持 SDK 自动更新，已关闭。请通过下载中心或用户中心获取新版本。';
        }

        $this->plugin_file = (string) $c['plugin_file'];
        $this->buy_url     = (string) $c['buy_url'];
        $this->menu        = $c['menu'];
        $this->menu_slug   = $c['menu_slug'] !== '' ? $c['menu_slug'] : 'at8lic-sdk-' . sanitize_key($this->product);
        $this->text_domain = (string) $c['text_domain'];

        $this->warnings = array_merge($this->warnings, $this->security_warnings());
    }

    /**
     * 配置层的安全校验（整改 V3 §三十/§三十一）。
     *
     * @return string[]
     */
    private function security_warnings()
    {
        $out = [];

        // §三十一：禁止 HTTP 明文传输授权凭证。localhost 允许 http 便于本地联调。
        $scheme = strtolower((string) wp_parse_url($this->server_url, PHP_URL_SCHEME));
        $host   = strtolower((string) wp_parse_url($this->server_url, PHP_URL_HOST));

        if ($scheme !== '' && $scheme !== 'https' && !self::is_local_host($host)) {
            $out[] = '授权服务器地址使用了 http 明文传输，授权凭证可能被中间人截获。生产环境必须使用 https。';
        }
        if ($scheme === '') {
            $out[] = '授权服务器地址缺少协议（应形如 https://www.at8.fun），将按当前页面协议访问。';
        }

        return $out;
    }

    private static function is_local_host($host)
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * 目标 URL 是否为本机地址（放行 HTTP 明文，便于本地联调）。
     */
    public static function is_local_endpoint($url)
    {
        return self::is_local_host(strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST)));
    }

    /** 本产品类型对应的 WordPress 更新能力 */
    public function update_capability()
    {
        return self::TYPES[$this->product_type]['cap'];
    }

    /** 本产品类型对应的 update transient 过滤器 */
    public function update_filter()
    {
        return self::TYPES[$this->product_type]['filter'];
    }

    /** 是否支持 SDK 托管的自动更新 */
    public function supports_auto_update()
    {
        return $this->auto_update && isset(self::TYPES[$this->product_type]);
    }

    public function plugin_basename()
    {
        return $this->plugin_file ? plugin_basename($this->plugin_file) : '';
    }

    public function site_url()
    {
        return home_url();
    }

    /** 本产品状态 option key（autoload=false） */
    public function state_option()
    {
        return 'at8lic_sdk_' . sanitize_key($this->product) . '_state';
    }

    public function api_url($path)
    {
        return $this->server_url . '/wp-json/at8-license/v1' . $path;
    }
}

} // class_exists guard
