<?php
/**
 * 统一响应解析与 schema 校验（整改 V3 §七/§八/§九/§十）。
 *
 * 为什么单独抽一层：
 *   过去 SDK 判断"成功"的条件是 `HTTP 2xx + 没报错`。
 *   这在真实部署里是危险的 —— WAF、反向代理、CDN、错误页面、异常插件
 *   都可能返回 HTTP 200 但 Body 根本不是 License Server 的响应。
 *   一旦把这种页面当成授权成功，客户就能白嫖 Pro 功能。
 *
 * 判定成功必须同时满足（V3 §九）：
 *   1. HTTP 状态码 2xx
 *   2. Body 是可解析的 JSON 对象
 *   3. success === true（严格布尔真，不是"有就当真"）
 *   4. data 是数组（不是 null / 不是字符串 / 不是标量）
 *
 * 服务端两种响应形态都要认（V3 §八）：
 *
 *   A. 业务层成功
 *      {"success":true,"code":"AT8_OK","message":"OK","data":{...}}
 *
 *   B. 业务层失败
 *      {"success":false,"code":"AT8_LICENSE_EXPIRED","message":"…",
 *       "data":null,"legacy_code":"LICENSE_EXPIRED"}
 *
 *   C. permission_callback 拒绝（WP 核心序列化 WP_Error，只有三个顶层键）
 *      {"code":"AT8_AUTH_FAILED","message":"…",
 *       "data":{"status":401,"success":false,"legacy_code":"AUTH_FAILED"}}
 *
 *   注意 C 形态的 data 内含 status，它是错误载荷而不是业务载荷，
 *   不能被误当成业务数据——这是 P1-01 改动 WP_Error 返回后引入的坑。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('AT8SDK_Response')) {

class AT8SDK_Response
{
    /**
     * 顶层四字段是否齐备。
     *
     * C 形态（WP_Error）只有 code / message / data 三个键，
     * 所以本方法对 C 形态返回 false 是**正确行为**，不是缺陷。
     *
     * @return bool
     */
    public static function is_well_formed($res)
    {
        if (!is_array($res) || empty($res['data']) || !is_array($res['data'])) {
            return false;
        }
        $d = $res['data'];

        return array_key_exists('success', $d)
            && array_key_exists('code', $d)
            && array_key_exists('message', $d)
            && array_key_exists('data', $d);
    }

    /**
     * 是否成功（整改 V3 §九）。
     *
     * 严格条件：HTTP 2xx + success 严格为真。
     * 缺 success 字段时**不**判定为成功 —— 这正是要修的那个坑。
     */
    public static function is_success($res)
    {
        if (!is_array($res) || $res['http'] === null || empty($res['data']) || !is_array($res['data'])) {
            return false;
        }

        $http = (int) $res['http'];
        if ($http < 200 || $http >= 300) {
            return false;
        }

        $d = $res['data'];

        // C 形态（WP_Error）无顶层 success：必然不是业务成功
        if (!array_key_exists('success', $d)) {
            return false;
        }
        if ($d['success'] !== true) {
            return false;
        }

        // 成功必须带数组载荷；data 为 null / 标量都算畸形
        return isset($d['data']) && is_array($d['data']);
    }

/**
     * 取业务数据；非成功或畸形一律返回 null。
 *
 * 形态判定只认一种：新版嵌套 {success, code, message, data:{…}}。
 * 旧版平铺格式不再兼容 —— 它没有 `data` 字段，无法满足
 * 「success===true 且 data 为合法数组」这条判定（整改 V3 §九）。
 * 迁移期的旧服务端已下线，继续兼容反而会削弱严格性。
 *
 * @param array $res
 * @param string[] $required 必填字段（V3 §十 schema 校验）
 * @return array|null
 */
    public static function unwrap($res, $required = [])
    {
        if (!self::is_success($res)) {
            return null;
        }

        $payload = $res['data']['data'];

        if (!self::has_required($payload, $required)) {
            return null;
        }

        return $payload;
    }

    /**
     * 必填字段校验。
     *
     * 用 `=== null` 判定而不是 array_key_exists：字段存在但值为 null
     * 与字段缺失一样不可用。服务端把 activation_id 传成 null 时，
     * SDK 拿不到可用的激活实例，不能当成正常响应继续。
     */
    private static function has_required($payload, $required)
    {
        if (!is_array($payload)) {
            return false;
        }
        foreach ($required as $field) {
            if (!array_key_exists($field, $payload) || $payload[$field] === null) {
                return false;
            }
        }
        return true;
    }

    /**
     * 统一错误码（兼容 V3 §八 的三种形态）。
     *
     * 优先新码 AT8_*；没有新码时回退 legacy_code（顶层或 data 内）。
     */
    public static function error_code($res)
    {
        if (empty($res['data']) || !is_array($res['data'])) {
            return '';
        }
        $d = $res['data'];

        $code = isset($d['code']) ? (string) $d['code'] : '';
        if ($code !== '' && strpos($code, 'AT8_') === 0) {
            return $code;
        }

        // legacy_code 可能在顶层（形态 B），也可能在 data 里（形态 C）
        if (!empty($d['legacy_code'])) {
            return (string) $d['legacy_code'];
        }
        if (!empty($d['data']['legacy_code'])) {
            return (string) $d['data']['legacy_code'];
        }

        return $code;
    }

    /**
     * 用户可见的错误消息（整改 V3 §四十八）。
     *
     * 白名单化：只透传服务端给的业务文案，且做长度与内容收敛。
     * 绝不透传 HMAC secret / SQL 错误 / 服务器路径 / 堆栈。
     */
    public static function error_message($res)
    {
        if (empty($res['data']['message'])) {
            return '验证失败';
        }

        $msg = (string) $res['data']['message'];

        // 收敛：出现这些特征说明服务端泄漏了内部细节
        $forbidden = ['SELECT ', 'INSERT ', 'UPDATE ', 'SQL', 'Stack trace', '#0 ', '/var/www', 'wp-content', 'wp-config'];
        foreach ($forbidden as $needle) {
            if (stripos($msg, $needle) !== false) {
                return '授权服务器返回了无法理解的响应，请稍后重试或联系客服。';
            }
        }

        $msg = trim(strip_tags($msg));
        if ($msg === '' || mb_strlen($msg) > 200) {
            return '授权服务器返回了无法理解的响应，请稍后重试或联系客服。';
        }

        return $msg;
    }

    /**
     * 端点级 schema 校验（整改 V3 §十）。
     *
     * /license/activate 与 /license/verify 的必填字段。
     * 说明：'lifetime' 允许为 false（它本身就是布尔量），
     * 但不允许为 null（那是字段缺失）。has_required 已用 === null 判定。
     */
    public static function required_for_license()
    {
        return ['status', 'expires_at', 'lifetime', 'product', 'update_access', 'update_access_until'];
    }

    /** /license/activate 额外必填：激活实例与一次性 secret */
    public static function required_for_activate()
    {
        return array_merge(self::required_for_license(), ['activation_id', 'domain', 'activation_secret']);
    }

    /** HTTP 层可重试的状态码（整改 V3 §二十五） */
    public static function is_retryable_http($http)
    {
        return in_array((int) $http, [502, 503, 504], true);
    }

    /** HTTP 层**禁止**重试的状态码（整改 V3 §二十五：业务错误立即返回） */
    public static function is_terminal_http($http)
    {
        return in_array((int) $http, [400, 401, 403, 404, 409, 422], true);
    }
}

} // class_exists guard