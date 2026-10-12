<?php
/**
 * 授权制 Pro 自动更新（整改 V4）。
 *
 * ============================ 核心变更（V4 §二/§四） ============================
 *
 * 旧方案（已废弃）：
 *   package = admin-ajax.php?action=at8sdk_download&product=xxx
 *        ↓
 *   WordPress WP_Upgrader::download_package() → download_url() 发出**服务端 loopback 请求**
 *        ↓
 *   proxy_download() → check_ajax_referer() → 必然 403
 *
 * 旧方案的根本问题：WP_Upgrader 不是一个浏览器。它用 `download_url()` 在**服务端**
 * 发起 HTTP 请求，URL 里既没有 nonce，也不会带上能通过 `check_ajax_referer()` 的凭据。
 * 这条路径在真实 WordPress 里从来就跑不通。
 *
 * 新方案：
 *   update_plugins transient 里只放一个**内部标识 URL**
 *        ↓
 *   WordPress WP_Upgrader 走到 download_package()
 *        ↓
 *   upgrader_pre_download 过滤器（V4 §六）← SDK 在这里接管
 *        ↓
 *   check_remote(force=true) 向 License Server 换一枚新的 300 秒令牌
 *        ↓
 *   HTTPS 下载 ZIP（禁跳转）
 *        ↓
 *   SHA-256 校验（hash_equals，失败即删临时文件并返回 WP_Error）
 *        ↓
 *   wp_tempnam() 落临时文件，返回本地路径
 *        ↓
 *   WordPress 原生 Unpack_Upgrader 接手：unpack → install → 删除临时文件
 *
 * ============================== 安全边界（V4 §四十一） ==============================
 *
 * 绝不：eval / include 远端 / 下载 PHP 执行 / 写入插件目录。
 * SDK 只负责「拿到经校验的本地 ZIP 路径」，安装全部交给 WordPress 原生安装器。
 *
 * 落盘说明（V4 §二十）：
 *   旧文档写「全程不落盘」不准确——WP_Upgrader 本身需要一个本地 package 文件。
 *   准确表述：SDK 不长期保存安装包、不写入插件目录、不写入 uploads；
 *   仅创建 Upgrader 所需的临时 ZIP，安装完成���由 WordPress 清理。
 *
 * ================================ 认证来源（V4 §二十一） ================================
 *
 * package 下载**不依赖** `current_user_can()` 或 AJAX nonce——因为 WP-Cron 自动更新
 * 场景下没有"当前用户"。真正的授权来自：
 *   License Server + Activation Secret（HMAC 签名）+ Download Token + SHA-256。
 *
 * WordPress 自身的 `update_plugins` 权限与更新流程**未被绕过**（V4 §二十二），
 * SDK 只负责 package 来源。
 *
 * admin-ajax 下载接口仍然保留（V4 §二十三），仅用于用户手动下载 / 调试。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('AT8SDK_Updater')) {

class AT8SDK_Updater
{
    /** 更新信息缓存时长 */
    const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    /**
     * package 内部标识前缀（V4 §五）。
     *
     * transient 里放的是这个标识，不是真实的 download_url——
     * 真实地址含 download_token 短时凭证，绝不能落进 options。
     * 主机名用 .invalid 保留域（RFC 2606），保证永不可解析。
     */
    const PACKAGE_SCHEME = 'https://at8sdk-package.invalid/';

    /**
     * 下载令牌刷新轮数上限（V5 §二十五）。
     *
     * 第 1 轮正常下载；若撞上令牌过期（401/403/410），
     * 第 2 轮重新 check_remote(true) 换新令牌后再试。
     * 只给一次机会——两次都失败说明不是令牌问题，不该继续打服务器。
     */
    const TOKEN_RETRY_ROUNDS = 2;

    /** @var array<string, AT8SDK_Config> product => config */
    private static $configs = [];

    /** @var array<string, bool> filter => 已挂载标记 */
    private static $hooked = [];

    /** upgrader_pre_download 的独立挂载标记（与产品类型解耦，见 init() 注释） */
    private static $pre_download_hooked = false;

    /**
     * check_remote() 最近一次失败的内部原因码。
     *
     * check_remote 的返回类型是 array|null，无法携带原因。
     * 直接返回 WP_Error 会让 inject_update（列表展示）那条路径
     * 每秒都构造一个错误对象出来，纯浪费。
     *
     * 所以用这个静态字段传递原因，由下载链路读取并翻译成 WP_Error。
     * 取值：not_activated / license_invalid / no_update_access /
     *       server_unavailable / invalid_response / ''（无错误）
     *
     * @var string
     */
    private static $last_error = '';

    public static function init($config)
    {
        self::$configs[$config->product] = $config;

        /*
         * upgrader_pre_download 与产品类型无关，必须**无条件**注册一次。
         *
         * 它不能放在下面的 per-filter 守卫里：
         * 守卫按 update_filter() 去重，而 update_filter() 依赖产品类型。
         * 若第一个初始化的恰好是主题产品（守卫提前 return），
         * 后续插件产品就再也挂不上这个过滤器，更新会静默失效。
         *
         * 单独用 $pre_download_hooked 去重，与产品类型解耦。
         */
        if (!self::$pre_download_hooked) {
            self::$pre_download_hooked = true;
            add_filter('upgrader_pre_download', [__CLASS__, 'pre_download'], 10, 4);
        }

        // V4 §三：主题产品不进入自动更新链路
        if (!$config->supports_auto_update()) {
            return;
        }

        $filter = $config->update_filter();

        // 每个 filter 只挂一次；多产品共用同一类型时不会重复注入
        if (isset(self::$hooked[$filter])) {
            return;
        }
        self::$hooked[$filter] = true;

        add_filter($filter, [__CLASS__, 'inject_update']);

        // V4 §二十三：手动下载接口仍保留
        add_action('wp_ajax_at8sdk_download', [__CLASS__, 'proxy_download']);
    }

    /* =====================================================================
     * upgrader_pre_download —— SDK 的 package 来源（V4 §四/§七）
     * ===================================================================== */

    /**
     * 接管 WordPress Upgrader 的 package 下载。
     *
     * @param bool|WP_Error $reply     上一个过滤器返回的值（默认 false）
     * @param string        $package   待下载的 package URL
     * @param WP_Upgrader   $upgrader  实例（未使用）
     * @param array         $hook_extra 上下文，含 'plugin' / 'theme'
     * @return string|WP_Error|bool 本地 ZIP 路径 / 错误 / $reply（不拦截）
     */
    public static function pre_download($reply, $package, $upgrader = null, $hook_extra = [])
    {
        /*
         * V5 §六第一原则：不是 AT8 SDK package → 原样 return $reply。
         *
         * 三态判定（V5 §十九要求区分 invalid package marker）：
         *   null       → 完全不是我们的（WordPress.org / 第三方 / 本地文件）→ 放行
         *   WP_Error   → 是我们的 scheme 但 marker 非法 → 明确报错，不能放行
         *                （放行会让 WordPress 去 DNS 请求 at8sdk-package.invalid）
         *   string     → 合法 product slug → 进入 AT8 下载流程
         */
        $product = self::product_from_package($package);
        if ($product instanceof WP_Error) {
            return $product;
        }
        if ($product === null) {
            return $reply;
        }

        $config = isset(self::$configs[$product]) ? self::$configs[$product] : null;
        if (!$config) {
            /*
             * V4 §九 / V5 §十六：marker 指向一个本机未注册的产品。
             * 这不是「第三方 package」——是我们自己的 scheme。
             * 放行同样会导致 DNS 请求失败，所以必须明确报错。
             */
            return new WP_Error(
                'at8sdk_unknown_product',
                '更新包对应的产品未在本机注册，已阻止安装。'
            );
        }

        if (!$config->supports_auto_update()) {
            return new WP_Error(
                'at8sdk_unsupported_product_type',
                '该产品类型不支持通过授权系统更新，请前往下载中心获取新版本。'
            );
        }

        // V4 §三十三 / V5 §十七：多产品隔离——用本产品自己的实例，不碰别人的授权
        $license = AT8_License_SDK::license($product);
        if (!$license || $license->get_key() === '') {
            return new WP_Error(
                'at8sdk_not_activated',
                '授权未激活，无法下载更新包。'
            );
        }

        // V5 §九：授权状态实时检查。transient 里有更新信息不代表现在还有权益。
        if (!$license->is_valid()) {
            return new WP_Error(
                'at8sdk_license_invalid',
                '授权已失效（过期 / 吊销 / 暂停 / 域名不符），已阻止更新。'
            );
        }
        if (!$license->has_update_access()) {
            return new WP_Error(
                'at8sdk_update_not_allowed',
                '当前授权不包含更新权益，或更新权益已过期。'
            );
        }

        /*
         * V5 §二十五：下载令牌 300 秒即过期。
         *
         * 第一次下载可能恰好撞上令牌过期（服务端返回 401/403/410），
         * 此时必须重新 check_remote(true) 换一枚新令牌再试一次，
         * 而不是让用户看到「下载失败」。
         *
         * 注意：只有**令牌类**失败才重试。哈希不符、临时文件失败等
         * 属于确定性失败，重试没有意义。
         */
        $result = self::download_with_fresh_token($config, $product);

        return $result;
    }

    /**
     * 取令牌 → 下载 → 校验 → 落临时文件，令牌过期时自动重取一次。
     *
     * @return string|WP_Error 本地 ZIP 绝对路径或错误
     */
    private static function download_with_fresh_token($config, $product)
    {
        // 最多两轮：第 2 轮是「令牌过期后重取」
        for ($round = 1; $round <= self::TOKEN_RETRY_ROUNDS; $round++) {

            // V4 §十 / V5 §八：每一轮都 force=true，绝不复用过期令牌
            $data = self::check_remote($config, true);
            if (!$data) {
                /*
                 * V5 §十九：必须区分「服务器连不上」和「服务器返回了垃圾」。
                 * check_remote 用 self::$last_error 传递原因——
                 * 只返回 null 的话两者会塌缩成同一个错误码，
                 * 运维看到「无法连接服务器」会去查网络，而真因是服务端改了接口。
                 */
                $why = self::$last_error;

                if ($why === 'invalid_response') {
                    return new WP_Error(
                        'at8sdk_invalid_update_payload',
                        '授权服务器返回的更新信息不完整或格式错误，已阻止更新。'
                    );
                }
                if ($why === 'license_invalid') {
                    return new WP_Error(
                        'at8sdk_license_invalid',
                        '授权已失效（过期 / 吊销 / 暂停 / 域名不符），已阻止更新。'
                    );
                }
                if ($why === 'no_update_access') {
                    return new WP_Error(
                        'at8sdk_update_not_allowed',
                        '当前授权不包含更新权益，或更新权益已过期。'
                    );
                }
                if ($why === 'not_activated') {
                    return new WP_Error(
                        'at8sdk_not_activated',
                        '授权未激活，无法下载更新包。'
                    );
                }
                return new WP_Error(
                    'at8sdk_server_unavailable',
                    '无法连接授权服务器，请稍后重试。'
                );
            }

            if (empty($data['update_available'])) {
                return new WP_Error(
                    'at8sdk_no_update',
                    '授权服务器未返回可用更新（产品不匹配 / 版本已是最新）。'
                );
            }

            $invalid = self::validate_update_payload($data);
            if ($invalid !== true) {
                return $invalid;   // 已是 WP_Error
            }

            $res = self::fetch_package($product, (string) $data['download_url']);

            if (is_wp_error($res)) {
                // 只对令牌类失败重试
                if (self::is_token_error($res)) {
                    if ($round < self::TOKEN_RETRY_ROUNDS) {
                        error_log('[AT8 SDK] 下载令牌失效，重新换取：product=' . $product
                            . ' code=' . $res->get_error_code());
                        continue;
                    }
                    /*
                     * 最后一轮仍是令牌问题 → 明确告诉用户「刷新凭证失败」，
                     * 而不是把内部的 token_rejected 抛出去。
                     * 前者指向「稍后重试 / 手动下载」，后者会让人以为是授权本身坏了。
                     */
                    error_log('[AT8 SDK] 令牌刷新后仍被拒绝：product=' . $product);
                    return new WP_Error(
                        'at8sdk_token_refresh_failed',
                        '下载凭证刷新后仍无法使用，请稍后重试或前往下载中心手动下载。'
                    );
                }
                return $res;
            }

            // V5 §十二：SHA-256 强制校验，失败即删临时文件并报错
            $expected = (string) $data['sha256'];
            $actual   = hash('sha256', $res);

            if (!hash_equals($expected, $actual)) {
                error_log('[AT8 SDK] 更新包 SHA-256 校验失败，已丢弃：product=' . $product);
                return new WP_Error(
                    'at8sdk_integrity_failed',
                    '安装包完整性校验失败，已阻止安装。可能是传输被篡改，请联系客服。'
                );
            }

            // V4 §十七/§十八：落 WordPress 临时文件
            $tmp = self::write_temp_package($product, (string) $data['version'], $res);
            if (is_wp_error($tmp)) {
                return $tmp;
            }

            // V4 §十九 / V5 §十一：返回路径，WordPress 负责 unpack / install / 删除
            return $tmp;
        }

        /*
         * 理论上不可达：循环内每条分支都 return。
         * 保留它作为兜底——若将来有人给循环加了新的 continue 路径，
         * 这里至少能给用户一个明确的 WP_Error，而不是 null 被上层当成功处理。
         */
        return new WP_Error(
            'at8sdk_token_refresh_failed',
            '下载凭证刷新失败，请稍后重试或前往下载中心手动下载。'
        );
    }

    /**
     * 从 License Server 下载 ZIP（V5 §十）。
     *
     * @return string|WP_Error ZIP 内容或错误
     */
    private static function fetch_package($product, $download_url)
    {
        // V4 §十二 / V5 §十：强制 HTTPS
        if (strtolower((string) wp_parse_url($download_url, PHP_URL_SCHEME)) !== 'https') {
            return new WP_Error('at8sdk_insecure_url', '下载地址不是 HTTPS，已拒绝下载。');
        }

        // V4 §十三 / §三十七：禁跳转 + 强制证书校验
        $res = wp_remote_get($download_url, [
            'timeout'     => 60,
            'sslverify'   => true,
            'redirection' => 0,
        ]);

        if (is_wp_error($res)) {
            $code = $res->get_error_code();

            // V5 §十九：区分 SSL 错误与普通网络错误，便于用户与日志判断
            if (self::is_ssl_error($code)) {
                error_log('[AT8 SDK] 更新包下载 SSL 校验失败：product=' . $product
                    . ' reason=' . $code);
                return new WP_Error(
                    'at8sdk_ssl_error',
                    '与授权服务器的 HTTPS 连接校验失败，已阻止下载。'
                );
            }

            error_log('[AT8 SDK] 更新包下载失败：product=' . $product . ' reason=' . $code);
            return new WP_Error('at8sdk_download_failed', '下载更新包失败，请稍后重试。');
        }

        $code = (int) wp_remote_retrieve_response_code($res);

        /*
         * V5 §十 / §十九：禁止自动跟随未知重定向。
         * redirection=0 下 WP 不会跳转，3xx 会原样返回到这里——
         * 必须当作错误处理，否则等于允许授权服务器把我们指向任意主机。
         */
        if ($code >= 300 && $code < 400) {
            error_log('[AT8 SDK] 更新包下载返回重定向，已拒绝：product=' . $product
                . ' http=' . $code);
            return new WP_Error(
                'at8sdk_redirect_detected',
                '下载地址返回了重定向，出于安全考虑已拒绝下载。'
            );
        }

        // V5 §二十五：401/403/410 = 令牌失效 / 过期 / 权限失效
        if ($code === 401 || $code === 403 || $code === 410) {
            error_log('[AT8 SDK] 下载令牌被拒绝：product=' . $product . ' http=' . $code);
            return new WP_Error(
                'at8sdk_token_rejected',
                '下载凭证已失效。'
            );
        }

        if ($code !== 200) {
            error_log('[AT8 SDK] 更新包下载返回非 200：product=' . $product . ' http=' . $code);
            return new WP_Error(
                'at8sdk_http_error',
                '下载更新包失败（服务器返回 ' . $code . '），请稍后重试。'
            );
        }

        $body = wp_remote_retrieve_body($res);
        if ($body === '' || $body === null) {
            return new WP_Error('at8sdk_empty_package', '下载内容为空，已阻止安装。');
        }

        return $body;
    }

    /** 令牌类错误：值得重新 check_remote(true) 换一枚 */
    private static function is_token_error($res)
    {
        return $res->get_error_code() === 'at8sdk_token_rejected';
    }

    /**
     * cURL / WordPress HTTP API 的 SSL 相关错误码。
     *
     * 这些错误说明连接根本没建立成（证书链问题 / 主机名不匹配 /
     * 根证书缺失），重试没有意义，必须与普通网络抖动区分开。
     */
    private static function is_ssl_error($code)
    {
        return in_array((string) $code, [
            'ssl_peer_verify_failed',
            'ssl_ca_bundle_not_found',
            'ssl_verify_failed',
            'https_ssl_failed',
            'ssl_connect_error',
            'ssl_hostname_mismatch',
        ], true);
    }

    /**
     * 创建临时 ZIP 并写入内容。
     *
     * V4 §十八：wp_tempnam() 失败就返回 WP_Error，
     * **绝不** fallback 到 wp-content 或插件目录。
     *
     * @return string|WP_Error 临时文件绝对路径
     */
    private static function write_temp_package($product, $version, $body)
    {
        $filename = sanitize_file_name($product . '-' . $version . '.zip');

        // wp_tempnam 会创建文件并返回路径；它用的是 WP 的临时目录
        $tmp = wp_tempnam($filename);
        if (!$tmp) {
            return new WP_Error(
                'at8sdk_tempnam_failed',
                '无法创建临时文件，请检查 PHP 临时目录权限。'
            );
        }

        $written = file_put_contents($tmp, $body);
        if ($written === false || $written !== strlen($body)) {
            // 写失败必须清理，不能留半截文件在磁盘上
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
            return new WP_Error(
                'at8sdk_write_failed',
                '写入临时文件失败，请检查磁盘空间。'
            );
        }

        return $tmp;
    }

    /**
     * 从 package URL 反解出 product slug（三态判定，V5 §六/§十九）。
     *
     * 格式：https://at8sdk-package.invalid/{product}
     *
     * @return string|null|WP_Error
     *   string  → 合法 product slug，进入 AT8 下载流程
     *   null    → 完全不是 AT8 的 package（wordpress.org / 第三方 / 本地文件）→ 调用方原样放行
     *   WP_Error→ 命中了 AT8 scheme 但 marker 本身非法（空 / 含路径 / 不可解析）
     *             必须明确报错：**绝不能放行**，否则 WordPress 会真的去
     *             DNS 请求 at8sdk-package.invalid，白等一次超时。
     */
    public static function product_from_package($package)
    {
        $package = (string) $package;

        // 先精确判断 scheme 前缀，相似但不同的域必须放行
        if (strpos($package, self::PACKAGE_SCHEME) !== 0) {
            return null;
        }

        $path = substr($package, strlen(self::PACKAGE_SCHEME));
        $path = trim($path, '/');

        // 命中了我们的 scheme，但 marker 内容不合法
        if ($path === '' || strpos($path, '/') !== false) {
            return new WP_Error(
                'at8sdk_invalid_package_marker',
                '更新包标识格式无效，已阻止安装。'
            );
        }

        $product = sanitize_key($path);
        if ($product === '') {
            return new WP_Error(
                'at8sdk_invalid_package_marker',
                '更新包标识格式无效，已阻止安装。'
            );
        }

        return $product;
    }

    /* =====================================================================
     * 更新检查
     * ===================================================================== */

    /**
     * 向服务器查询更新。
     *
     * @param bool $force true = 强制重新查询（下载前必须 true，令牌 300 秒即过期）
     * @return array|null
     *
     * @see self::$last_error 失败原因，供调用方区分错误码
     */
    private static function check_remote($config, $force = false)
    {
        $license = AT8_License_SDK::license($config->product);
        if (!$license || $license->get_key() === '') {
            self::$last_error = 'not_activated';
            return null;
        }

        // V4 §二十七/§二十八：is_valid() 与 has_update_access() 必须同时满足。
        // 无授权 → 不出现更新；权益过期 → Pro 继续运行但不能更新。
        if (!$license->is_valid()) {
            self::$last_error = 'license_invalid';
            return null;
        }
        if (!$license->has_update_access()) {
            self::$last_error = 'no_update_access';
            return null;
        }

        $cache_key = 'at8sdk_upd_' . sanitize_key($config->product);
        if (!$force) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                /*
                 * 缓存里刻意没有 download_url（V5 §七）。
                 * 所以这里**不能**把缓存当完整载荷返回——
                 * 否则非 force 路径下 $data['download_url'] 会缺失，
                 * 任何依赖它的调用方都会踩到 undefined index。
                 *
                 * process() 只用 version/tested/requires，够用；
                 * 需要下载地址的路径（pre_download）一律 force=true，不走缓存。
                 */
                return $cached;
            }
        }

        $current = self::current_version($config);

        $secret = $license->get_secret();
        $body   = [
            'product'  => $config->product,
            'version'  => $current,
            'site_url' => $config->site_url(),
        ];

        $res = $secret !== ''
            ? $license->client()->post_with_secret('/update/check', $body, $secret)
            : $license->client()->post('/update/check', $body, $license->get_key());

        // V4 §十一/§十二：download_url 必须来自服务端且是 HTTPS
        $d = AT8SDK_Response::unwrap($res);
        if ($d === null) {
            /*
             * V5 §十九：unwrap 失败有两种完全不同的原因——
             *   · 网络故障 / 5xx / 限流 → 服务器问题（server_unavailable）
             *   · 200 + success 但载荷残缺 → 服务端返回了垃圾（invalid_update_payload）
             * 分开报，运维才知道该查网络还是查接口。
             */
            self::$last_error = ($res['http'] === null || $res['http'] >= 500 || $res['http'] === 429)
                ? 'server_unavailable'
                : 'invalid_response';
            return null;
        }

        // 畸形响应**不进 transient**，也返回 null（不向调用者泄露脏数据）
        if (self::validate_update_payload($d) !== true) {
            self::$last_error = 'invalid_response';
            return null;
        }

        self::$last_error = '';
        /*
         * V5 §七：缓存进 transient 的内容必须剥掉 download_url。
         *
         * download_url 内含 download_token（300 秒短时凭证），
         * 而 transient 缓存 12 小时。留着它等于把一枚「虽然马上过期
         * 但格式完整」的凭证长期落盘。真正的地址在下载那一刻重取。
         *
         * 注意：返回给调用方的仍是完整的 $d（下载流程需要 download_url），
         * 只有落盘的那一份是剥离过的。
         */
        $cached = $d;
        unset($cached['download_url'], $cached['download_token'], $cached['sha256']);

        set_transient($cache_key, $cached, self::CACHE_TTL);
        return $d;
    }

    /**
     * /update/check 响应 schema 校验。
     *
     * 有更新时必须有：version、download_url（https）、合法格式的 sha256。
     * 字段缺失或畸形一律视为无效响应——宁可不给更新，也不能把脏数据塞进 transient。
     *
     * V5 §十九：失败必须返回具体的 WP_Error，不能只回一个 false。
     * 「缺 download_url」和「缺 sha256」是两种不同的问题，
     * 排查时需要能一眼看出是服务端哪一环没给。
     *
     * @return true|WP_Error
     */
    private static function validate_update_payload($d)
    {
        if (!is_array($d) || !array_key_exists('update_available', $d)) {
            return new WP_Error(
                'at8sdk_invalid_update_payload',
                '授权服务器返回的更新信息格式无法识别，已阻止更新。'
            );
        }

        if (empty($d['update_available'])) {
            return true;   // 无更新：只要 update_available 存在即可
        }

        if (empty($d['version']) || !is_string($d['version'])) {
            return new WP_Error(
                'at8sdk_missing_version',
                '授权服务器未返回新版本号，已阻止更新。'
            );
        }

        if (empty($d['download_url']) || !is_string($d['download_url'])) {
            return new WP_Error(
                'at8sdk_missing_download_url',
                '授权服务器未返回下载地址，该产品可能尚未上传安装包。'
            );
        }

        if (strtolower((string) wp_parse_url($d['download_url'], PHP_URL_SCHEME)) !== 'https') {
            return new WP_Error(
                'at8sdk_insecure_url',
                '授权服务器返回的下载地址不是 HTTPS，已阻止更新。'
            );
        }

        if (empty($d['sha256'])) {
            return new WP_Error(
                'at8sdk_missing_sha256',
                '授权服务器未返回安装包校验值，为安全起见已阻止更新。'
            );
        }

        if (!preg_match('/^[a-f0-9]{64}$/i', (string) $d['sha256'])) {
            return new WP_Error(
                'at8sdk_invalid_sha256',
                '授权服务器返回的安装包校验值格式无效，已阻止更新。'
            );
        }

        return true;
    }

    private static function current_version($config)
    {
        if (!$config->plugin_file) {
            return '0.0.0';
        }
        $data = get_file_data($config->plugin_file, ['Version' => 'Version']);
        return isset($data['Version']) && $data['Version'] !== '' ? $data['Version'] : '0.0.0';
    }

    /* =====================================================================
     * Update transient 注入
     * ===================================================================== */

    public static function inject_update($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }
        foreach (self::$configs as $config) {
            // 双重保险：即使 init 判断过，这里再挡一次
            if (!$config->supports_auto_update()) {
                continue;
            }
            $transient = self::process($transient, $config);
        }
        return $transient;
    }

    private static function process($transient, $config)
    {
        $basename = $config->plugin_basename();
        if ($basename === '' || isset($transient->response[$basename])) {
            return $transient;
        }

        $data = self::check_remote($config);
        if (!$data || empty($data['update_available'])) {
            return $transient;
        }

        $current = self::current_version($config);
        if (empty($data['version']) || version_compare($current, (string) $data['version'], '>=')) {
            return $transient;
        }

        $update = new stdClass();
        $update->slug         = dirname($basename);
        $update->plugin       = $basename;
        $update->new_version  = (string) $data['version'];

        /*
         * V4 §五 / §二十四：只写内部标识。
         * 绝不能把 download_url（携带 download_token）或 download_token
         * 写进 transient——那是 300 秒短时凭证，而 transient 缓存 12 小时。
         * 真实地址由 pre_download() 在下载那一刻重新向服务器索取。
         */
        $update->package      = self::PACKAGE_SCHEME . rawurlencode($config->product);
        $update->tested       = isset($data['tested']) ? (string) $data['tested'] : get_bloginfo('version');
        $update->requires     = isset($data['requires']) ? (string) $data['requires'] : '';
        $update->requires_php = isset($data['requires_php']) ? (string) $data['requires_php'] : '';

        // 供后台展示与调试的非敏感字段
        $update->at8_product   = $config->product;
        $update->at8_changelog = isset($data['changelog']) ? (string) $data['changelog'] : '';

        $transient->response[$basename] = $update;
        return $transient;
    }

    /* =====================================================================
     * 手动下载代理（V4 §二十三：保留，但不作为 Upgrader 的下载入口）
     * ===================================================================== */

    /**
     * 用户手动下载 / 调试用。
     *
     * **WordPress Upgrader 不再走这条路径**（见 pre_download）。
     * 保留它的理由：客户偶尔需要手动下载安装包、或排查安装失败原因。
     *
     * 这里的 current_user_can + check_ajax_referer 是合适的——
     * 因为它服务的是浏览器里的真实用户操作，不是服务端 loopback。
     */
    public static function proxy_download()
    {
        $product = isset($_REQUEST['product']) ? sanitize_key(wp_unslash($_REQUEST['product'])) : '';
        $config  = $product !== '' && isset(self::$configs[$product]) ? self::$configs[$product] : null;

        if (!$config) {
            wp_die('参数错误', '', ['response' => 400]);
        }

        // 能力必须与产品类型一致
        if (!current_user_can($config->update_capability())) {
            wp_die('无权限', '', ['response' => 403]);
        }

        // V4 §三十五：AJAX 路径继续保留 nonce 校验
        check_ajax_referer('at8sdk_download', 'nonce');

        if (!$config->supports_auto_update()) {
            wp_die('该产品类型不支持 SDK 自动更新', '', ['response' => 400]);
        }

        $data = self::check_remote($config, true);
        if (!$data || empty($data['update_available']) || empty($data['download_url'])) {
            wp_die('未获取到下载地址，请确认更新权益有效后重试', '', ['response' => 409]);
        }
        $valid = self::validate_update_payload($data);
        if ($valid !== true) {
            wp_die($valid->get_error_message(), '', ['response' => 502]);
        }

        $download_url = (string) $data['download_url'];
        if (strtolower((string) wp_parse_url($download_url, PHP_URL_SCHEME)) !== 'https') {
            wp_die('下载地址不是 HTTPS，已拒绝', '', ['response' => 400]);
        }

        $res = wp_remote_get($download_url, [
            'timeout'     => 60,
            'sslverify'   => true,
            'redirection' => 0,
        ]);

        if (is_wp_error($res)) {
            error_log('[AT8 SDK] 手动下载失败：product=' . $product . ' reason=' . $res->get_error_code());
            wp_die('下载失败，请稍后重试', '', ['response' => 502]);
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code !== 200) {
            error_log('[AT8 SDK] 手动下载非200：product=' . $product . ' http=' . $code);
            wp_die('下载失败，请稍后重试', '', ['response' => 502]);
        }

        $body = wp_remote_retrieve_body($res);
        if ($body === '') {
            wp_die('下载内容为空', '', ['response' => 502]);
        }

        if (!hash_equals((string) $data['sha256'], hash('sha256', $body))) {
            error_log('[AT8 SDK] 手动下载 SHA-256 校验失败：product=' . $product);
            wp_die('安装包完整性校验失败，已阻止下载。', '', ['response' => 502]);
        }

        $filename = sanitize_file_name($product . '-' . $data['version'] . '.zip');

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($body));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo $body;
        exit;
    }
}

} // class_exists guard