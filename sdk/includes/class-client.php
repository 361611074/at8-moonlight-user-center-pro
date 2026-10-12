<?php
/**
 * 签名 HTTP 客户端。
 *
 * 与服务器 AT8LIC_Security::canonical_request 严格对应（§63）：
 *
 *   canonical = AT8-SIG-V1 \n METHOD \n PATH \n TIMESTAMP \n NONCE \n SHA256(body) \n SCOPE
 *   signature = base64( HMAC-SHA256(canonical, secret) )
 *
 * 密钥有两种：
 *   - License Key（激活前）
 *   - Activation Secret（激活后，推荐；站点被吊销/解绑即失效）
 *
 * 超时（§64）：请求 10 秒，禁止无限等待。
 * 重试（§65）：最多 2 次，只对 timeout / 502 / 503 / 504 重试；
 *              401 / 403 / 400 与授权类错误一律不重试。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('AT8SDK_Client')) {

class AT8SDK_Client
{
    const SIG_VERSION    = 'AT8-SIG-V1';
    const SCOPE_CLIENT   = 'client';
    const TIMEOUT        = 10;
    const MAX_RETRIES    = 2;
    const RETRYABLE_HTTP = [502, 503, 504];

    /**
     * 重试退避间隔（毫秒，第 N 次失败后等待）。
     *
     * 整改 V3 §二十六：P2 非强制项。默认关闭——WordPress Cron 与
     * admin-ajax 场景不适合阻塞 sleep。开启方式：
     *   add_filter('at8sdk_retry_backoff', fn() => [250, 500]);
     * 返回空数组即不等待。
     *
     * @return int[]
     */
    public static function retry_backoff()
    {
        $ms = apply_filters('at8sdk_retry_backoff', []);
        return is_array($ms) ? array_values(array_map('intval', $ms)) : [];
    }

    /** @var AT8SDK_Config */
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    /**
     * POST 请求（用 License Key 签名）。
     *
     * @param string $path  API 路径，如 /license/activate
     * @param array  $body  JSON body
     * @return array{http:int|null, data:array|null, error:string|null}
     */
    public function post($path, $body, $key = '')
    {
        return $this->request('POST', $this->config->api_url($path), wp_json_encode($body), $key);
    }

    /** GET 请求（签名 body 为空串） */
    public function get($path, $key = '')
    {
        return $this->request('GET', $this->config->api_url($path), '', $key);
    }

    /**
     * 用 Activation Secret 发请求（激活后推荐）。
     *
     * Activation Secret 绑定到具体激活实例：站点被吊销或解绑后，
     * 服务端立即拒绝，而 License Key 可能仍在客户手上。
     */
    public function post_with_secret($path, $body, $secret)
    {
        return $this->request('POST', $this->config->api_url($path), wp_json_encode($body), '', $secret);
    }

    /**
     * 计算签名（与服务端规则完全一致）。
     */
    public static function sign($ts, $nonce, $method, $path, $body_json, $secret)
    {
        $canonical = implode("\n", [
            self::SIG_VERSION,
            strtoupper($method),
            self::normalize_path($path),
            (string) $ts,
            (string) $nonce,
            hash('sha256', (string) $body_json),
            self::SCOPE_CLIENT,
        ]);

        return base64_encode(hash_hmac('sha256', $canonical, (string) $secret, true));
    }

    /**
     * 路径归一化：去掉末尾斜杠（服务端同样处理）。
     */
    public static function normalize_path($path)
    {
        $path = (string) $path;
        return ($path === '' || $path === '/') ? '/' : rtrim($path, '/');
    }

    private function request($method, $url, $body_json, $key, $secret = '')
    {
        $path = self::normalize_path((string) wp_parse_url($url, PHP_URL_PATH));

        // §三十一：生产环境禁止 HTTP 明文。localhost 放行以便本地联调。
        if (!AT8SDK_Config::is_local_endpoint($url)) {
            $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
            if ($scheme !== 'https') {
                return ['http' => null, 'data' => null, 'error' => 'insecure_server_url'];
            }
        }

        $headers = [
            'Content-Type'             => 'application/json',
            'X-AT8-Timestamp'          => '',
            'X-AT8-Nonce'              => '',
            'X-AT8-Signature'          => '',
            'X-AT8-Product'            => $this->config->product,
        ];
        if ($secret !== '') {
            $headers['X-AT8-Activation-Secret'] = $secret;
        } else {
            $headers['X-AT8-Key'] = (string) $key;
        }

        $args = [
            'timeout'     => self::TIMEOUT,
            // §三十二：禁止跟随跳转。授权服务器若返回 302 到别处，
            // 签名与凭证都不该被转发到第三方主机。
            'redirection' => 0,
            'headers'     => $headers,
            'sslverify'   => true,
            // 伪装成 WordPress 核心的出站 UA：部分主机 WAF 会拦截非浏览器 UA
            'user-agent'  => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
                            . ' (AT8-License-SDK/' . AT8SDK_VERSION . ')',
        ];

        if ($method === 'POST') {
            // §五十四：签名用的 body 必须与实际发出的 body 是**同一个字符串**，
            // 不做任何二次编码或重组。
            $args['body']        = $body_json;
            $args['data_format'] = 'body';
        }

        // §二十四/§二十五：有限重试。
        //   可重试：网络故障、502/503/504
        //   禁重试：400/401/403/404/409/422 等业务错误 —— 重试只会浪费额度
        //            并把真实错误码淹没
        //   每次重试都重新生成 timestamp / nonce / signature，
        //   否则服务端 Nonce Protection 会把合法重试判成 Replay。
        $attempt    = 0;
        $backoff    = self::retry_backoff();
        $last       = ['http' => null, 'data' => null, 'error' => 'unknown'];

        while ($attempt <= self::MAX_RETRIES) {
            $ts = (string) time();

            // V4 §三十八：nonce 生成失败直接放弃这次请求，
            // 由调用层把 WP_Error 传给上层——绝不用弱随机数凑数。
            try {
                $nonce = self::nonce();
            } catch (Throwable $e) {
                return ['http' => null, 'data' => null, 'error' => 'nonce_generation_failed'];
            }

            $sign_secret = $secret !== '' ? $secret : $key;

            $headers['X-AT8-Timestamp'] = $ts;
            $headers['X-AT8-Nonce']     = $nonce;
            $headers['X-AT8-Signature'] = self::sign($ts, $nonce, $method, $path, $body_json, $sign_secret);
            $args['headers'] = $headers;

            $res = ($method === 'POST')
                ? wp_remote_post($url, $args)
                : wp_remote_get($url, $args);

            if (is_wp_error($res)) {
                $last = ['http' => null, 'data' => null, 'error' => $res->get_error_code()];
                if ($attempt >= self::MAX_RETRIES) {
                    break;
                }
                self::maybe_sleep($backoff, $attempt);
                $attempt++;
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code($res);
            $raw  = (string) wp_remote_retrieve_body($res);
            $data = json_decode($raw, true);
            $data = is_array($data) ? $data : null;

            // 明确不可重试：立即返回，把服务端真实的业务错误码交给上层判断
            if (AT8SDK_Response::is_terminal_http($code)) {
                return ['http' => $code, 'data' => $data, 'error' => null];
            }

            if (AT8SDK_Response::is_retryable_http($code)) {
                $last = ['http' => $code, 'data' => $data, 'error' => null];
                if ($attempt >= self::MAX_RETRIES) {
                    break;
                }
                self::maybe_sleep($backoff, $attempt);
                $attempt++;
                continue;
            }

            return ['http' => $code, 'data' => $data, 'error' => null];
        }

        return $last;
    }

    /** 可选的退避等待（默认关闭，见 retry_backoff()） */
    private static function maybe_sleep($backoff_ms, $attempt_index)
    {
        if (empty($backoff_ms)) {
            return;
        }
        $ms = isset($backoff_ms[$attempt_index]) ? (int) $backoff_ms[$attempt_index] : 0;
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    /**
     * 生成 nonce：32 位 hex（服务端要求 16–64 位 [A-Za-z0-9_-]）。
     *
     * 整改 V4 §三十八：random_bytes() 失败时**不再退化为弱 nonce**。
     *
     * 原实现 catch 里用 md5(uniqid()) 兜底 —— 那不是密码学安全的随机数。
     * nonce 是服务端 Replay Protection 的核心，弱 nonce 等于让重放攻击有了可乘之机。
     * 而且这个分支几乎不会触发（PHP 7+ 的 random_bytes 极少失败），
     * 保留一个"看起来能用"的兜底只会掩盖问题。
     *
     * 现在直接抛异常，由调用层的 try/catch 转成 WP_Error 告知用户。
     *
     * public 便于单元测试直接验证其格式与不可预测性（整改 V3 §五十）。
     *
     * @throws RuntimeException 无法获取密码学安全随机数
     */
    public static function nonce()
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Unable to generate secure nonce: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}

} // class_exists guard