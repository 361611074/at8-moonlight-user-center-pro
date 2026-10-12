<?php
/**
 * 授权状态机（SDK 核心）。
 *
 * 本地状态（§29）：
 *   status / expires_at / lifetime / product / activation_id /
 *   last_verified_at / grace_until / update_access_until / support_until /
 *   server_version / update_access / support_access
 *
 *   带 HMAC 签名（§29），客户直接改数据库 option 无法伪造 active。
 *
 * 状态（§66）：
 *   active / grace / expired / revoked / suspended / cancelled /
 *   invalid / server_unavailable / auth_failed
 *
 *   server_unavailable 与 invalid 是两回事：
 *   服务器暂时不可达 → 进入宽限期继续运行（§28）；
 *   服务器明确拒绝 → 立即停 Pro。绝不因超时直接禁用。
 *
 * is_valid() 只读本地状态：零 HTTP 请求，可在任何时机安全调用。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('AT8SDK_License')) {

class AT8SDK_License
{
    /** @var AT8SDK_Config */
    private $config;

    /** @var AT8SDK_Client */
    private $client;

    public function __construct($config, $client)
    {
        $this->config = $config;
        $this->client = $client;
    }

    /** @internal 供 Updater 等内部组件使用 */
    public function client()
    {
        return $this->client;
    }

    /* ---------------- 本地状态（零 HTTP） ---------------- */

    public function state()
    {
        $state = get_option($this->config->state_option(), []);
        return is_array($state) ? $state : [];
    }

    /**
     * 本地状态完整性签名（§29）。
     *
     * 防止客户直接改数据库字段白嫖：auth salt 存在于 wp-config.php，
     * 站点管理员也未必能伪造。这是低成本防线，不替代服务器端判定。
     */
    private static function state_sig(array $state)
    {
        unset($state['sig']);
        // 独立 salt 上下文，不与授权码哈希共用；ksort 固化键序，防未来改动引发全量失效
        ksort($state);
        return hash_hmac('sha256', wp_json_encode($state), wp_salt('auth') . '|at8sdk-state');
    }

    private function save_state($state)
    {
        $state['sig'] = self::state_sig($state);
        update_option($this->config->state_option(), $state, false);
    }

    /** 授权码（用户持有，可能与站点无关） */
    public function get_key()
    {
        $state = $this->state();
        return isset($state['key']) ? (string) $state['key'] : '';
    }

    /**
     * Activation Secret（绑定本站激活实例）。
     *
     * 激活成功后服务端一次性下发；本站被解绑或授权被吊销后立即失效。
     */
    public function get_secret()
    {
        $state = $this->state();
        return isset($state['activation_secret']) ? (string) $state['activation_secret'] : '';
    }

    public function get_activation_id()
    {
        $state = $this->state();
        return isset($state['activation_id']) ? (int) $state['activation_id'] : 0;
    }

    /** Pro 是否可用（本地判定，不发请求） */
    public function is_valid()
    {
        $state = $this->state();
        if (empty($state['key'])) {
            return false;
        }

        // 完整性校验：状态被手工篡改过则一律无效
        $sig = isset($state['sig']) ? $state['sig'] : '';
        if (!hash_equals(self::state_sig($state), (string) $sig)) {
            return false;
        }

        $now  = time();
        $status = isset($state['status']) ? $state['status'] : 'invalid';

        if ($status === 'active') {
            // 本地到期判定：授权期限已过则停 Pro（数据保留）
            if (!empty($state['lifetime']) || (int) $state['expires_at'] === 0 || (int) $state['expires_at'] > $now) {
                return true;
            }
            return false;
        }

        if ($status === 'grace' || $status === 'server_unavailable') {
            // 宽限期截止时刻必须已过（由 enter_grace 从 last_verified_at 推导，
            // 客户改 wp_options 也会因签名不匹配在上面的校验里被拦下）
            if ((int) (isset($state['grace_until']) ? $state['grace_until'] : 0) <= $now) {
                return false;
            }

            // 本地已知的到期时间仍然生效（防"到期后断网续命"，整改 V3 §十九）
            $expired_locally = empty($state['lifetime'])
                && (int) (isset($state['expires_at']) ? $state['expires_at'] : 0) !== 0
                && (int) $state['expires_at'] <= $now;

            return !$expired_locally;
        }

        return false;
    }

    /**
     * 更新权益是否可用。
     *
     * 「过期后仍可继续使用已安装版本，但不能更新」。
     *
     * V5 §九修正：必须同时看两个字段，不能只看日期。
     *
     * 原实现只判 update_access_until，忽略了服务端下发的 update_access 布尔位。
     * 后果：一个**不含更新权益**的套餐（比如只买 1 年使用、不含升级）
     * 只要还没到期，update_access_until 仍然是未来时间，
     * has_update_access() 就返回 true —— 权益位形同虚设。
     *
     * 两个字段的语义：
     *   update_access        服务端明确说「这个套餐包不包含更新」→ false 即一票否决
     *   update_access_until  包含更新但已过期 → 按时间判
     *
     * 迁移期兼容：老版本 state 里可能没有 update_access 字段，
     * 此时只看日期（等于旧行为），不把老用户误判成无权益。
     */
    public function has_update_access()
    {
        $state = $this->state();
        if (!$this->is_valid()) {
            return false;
        }

        // 服务端明确标记为「不含更新权益」→ 直接否决
        if (array_key_exists('update_access', $state) && !$state['update_access']) {
            return false;
        }

        $until = (int) (isset($state['update_access_until']) ? $state['update_access_until'] : 0);

        if (!empty($state['lifetime'])) {
            // 终身授权：未设置截止日 = 永久更新
            return $until === 0 || $until >= time();
        }

        return $until >= time();
    }

    public function status_label()
    {
        $state = $this->state();
        if (empty($state['key'])) {
            return '未激活';
        }
        $status = isset($state['status']) ? $state['status'] : 'invalid';
        if ($this->is_valid() && in_array($status, ['grace', 'server_unavailable'], true)) {
            return '宽限期（授权服务器暂时无法连接）';
        }
        $map = [
            'active'             => '已激活',
            'grace'              => '宽限期已过',
            'server_unavailable' => '服务器无法连接',
            'expired'            => '授权已过期',
            'revoked'            => '授权已被吊销',
            'suspended'          => '授权已被暂停',
            'cancelled'          => '授权已取消',
            'invalid'            => '授权无效',
            'auth_failed'        => '认证失败',
        ];
        return isset($map[$status]) ? $map[$status] : (string) $status;
    }

    public function expires_at()
    {
        $state = $this->state();
        return isset($state['expires_at']) ? (int) $state['expires_at'] : 0;
    }

    public function is_lifetime()
    {
        $state = $this->state();
        return !empty($state['lifetime']);
    }

    public function last_error()
    {
        $state = $this->state();
        return isset($state['message']) ? (string) $state['message'] : '';
    }

    public function last_verified_at()
    {
        $state = $this->state();
        return isset($state['last_verified_at']) ? (int) $state['last_verified_at'] : 0;
    }

    /* ---------------- 远程操作（用户点击 / cron 调用） ---------------- */

    /** 激活：成功返回 true；失败返回错误消息文本 */
    public function activate($key_input)
    {
        $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $key_input));
        if (strlen($key) < 16) {
            return '授权码格式不正确';
        }

        $res = $this->client->post('/license/activate', [
            'product'          => $this->config->product,
            'site_url'         => $this->config->site_url(),
            'site_identifier'  => $this->site_identifier(),
            'site_name'        => get_bloginfo('name'),
            'plugin_version'   => $this->plugin_version(),
            'wp_version'       => get_bloginfo('version'),
            'php_version'      => PHP_VERSION,
            'is_multisite'     => is_multisite(),
            'multisite_sub'    => $this->multisite_sub(),
        ], $key);

        if ($res['http'] === null) {
            return '无法连接授权服务器，请稍后重试';
        }

        // §十：activate 必须校验 schema——缺 activation_id / domain /
        // activation_secret 的响应一律不算成功，否则本地拿不到可用凭证。
        $d = AT8SDK_Response::unwrap($res, AT8SDK_Response::required_for_activate());
        if ($d !== null) {
            $this->save_state($this->build_state($d, $key, 'active'));
            AT8SDK_Scheduler::schedule($this->config);
            return true;
        }

        $code = AT8SDK_Response::error_code($res);
        $msg  = AT8SDK_Response::error_message($res);

        $state = $this->state();
        $state['key']     = $key;
        $state['status']  = $this->map_error_status($code);
        $state['message'] = $msg;
        $this->save_state($state);

        return $msg;
    }

    /** 解绑本站：成功后清空本地状态（含 Activation Secret） */
    public function deactivate()
    {
        $key    = $this->get_key();
        $secret = $this->get_secret();

        if ($key === '' && $secret === '') {
            return true;
        }

        $payload = [
            'product'        => $this->config->product,
            'site_url'       => $this->config->site_url(),
            'activation_id'  => $this->get_activation_id(),
        ];

        $res = $this->request('/license/deactivate', $payload, $key, $secret);
        if ($res['http'] === null) {
            return '无法连接授权服务器，请稍后重试';
        }

        if (AT8SDK_Response::unwrap($res) !== null) {
            delete_option($this->config->state_option());
            AT8SDK_Scheduler::unschedule($this->config);
            return true;
        }

        return AT8SDK_Response::error_message($res);
    }

    /**
     * 域名转移：旧域名解绑 + 新域名激活一步完成。
     *
     * 服务端在**同一事务**内完成（整改 V3 §十五）：任一步失败整体回滚，
     * 不会留下 old/new 双活。
     *
     * 转移成功后本站 URL 已变，需要重新激活以刷新本地状态。
     * 这里必须区分两种失败（整改 V3 §十六）：
     *   - transfer 本身失败        → 明确报错，状态不变
     *   - transfer 成功但后续激活网络失败 → **不能**报"转移失败"，
     *     因为服务端已经完成转移。此时应写入已转移的本地状态并提示用户稍后重试，
     *     下一次 verify 能恢复正确状态。
     */
    public function transfer($to_url)
    {
        $key    = $this->get_key();
        $secret = $this->get_secret();

        if ($key === '') {
            return '未激活，无法转移';
        }

        $to_url = esc_url_raw((string) $to_url);
        if ($to_url === '') {
            return '请填写有效的新域名';
        }

        $res = $this->request('/license/transfer', [
            'product'       => $this->config->product,
            'from_site_url' => $this->config->site_url(),
            'to_site_url'   => $to_url,
        ], $key, $secret);

        // 网络失败：服务端状态未知，不能声称转移失败
        if ($res['http'] === null) {
            return '无法连接授权服务器，转移状态未知。请稍后重新验证授权确认结果。';
        }

        $d = AT8SDK_Response::unwrap($res, ['transferred', 'activation_id', 'domain']);
        if ($d === null) {
            return AT8SDK_Response::error_message($res);
        }

        // 转移已在服务端提交（原子成功），先把已转移状态落盘：
        // 这样即使下面的二次激活失败，也不会把本地状态留在"旧域名仍有效"的错觉里。
        $state = $this->state();
        $state['status']              = 'active';
        $state['server_status']       = 'active';
        $state['domain']              = (string) $d['domain'];
        $state['activation_id']       = (int) $d['activation_id'];
        $state['last_verified_at']    = time();
        $state['message']             = '转移已提交，请稍后重新验证授权。';
        if (!empty($d['activation_secret'])) {
            // 新激活行的 secret 一次性下发，替换旧的
            $state['activation_secret'] = (string) $d['activation_secret'];
        } else {
            // 服务端未回传时必须清掉旧 secret —— 它已随旧域名作废，
            // 留着只会让每次 verify 都先吃一次 AT8_AUTH_FAILED 再回退。
            unset($state['activation_secret']);
        }
        $this->save_state($state);

        // 二次激活刷新本地状态（把新域名的完整 payload 拉下来）
        $again = $this->activate($key);
        if ($again === true) {
            return true;
        }

        // §十六：服务端转移已成功，这里只是本地状态刷新失败
        return '转移已提交，请稍后重新验证授权。';
    }

    /**
     * 定期复查（cron 调用）。
     *
     * 状态矩阵（整改 V3 §十八）——这是本方法的核心契约：
     *
     *   | Server 情况         | SDK 状态              | Pro |
     *   | ----------------- | ------------------- | --- |
     *   | active            | active              | 开   |
     *   | 网络失败              | grace               | 开   |
     *   | 5xx               | grace               | 开   |
     *   | 429               | grace               | 开   |
     *   | expired           | expired             | 关   |
     *   | revoked           | revoked             | 关   |
     *   | suspended         | suspended           | 关   |
     *   | cancelled         | cancelled           | 关   |
     *   | domain mismatch   | invalid             | 关   |
     *   | product mismatch  | invalid             | 关   |
     *   | signature invalid | invalid/auth_failed | 关   |
     *   | nonce replay      | invalid             | 关   |
     *
     * 分界线只有一条：**服务器是否给出了明确的业务结论**。
     * 明确的结论（过期/吊销/暂停/取消/域名不符/产品不符/签名错/nonce 重放）
     *   → 立即停 Pro。
     * 服务器说不清楚（网络故障 / 5xx / 429）
     *   → 进宽限期继续运行，绝不因超时禁用客户。
     */
    public function verify()
    {
        $key    = $this->get_key();
        $secret = $this->get_secret();

        if ($key === '' && $secret === '') {
            return;
        }

        $res = $this->request('/license/verify', [
            'product'  => $this->config->product,
            'site_url' => $this->config->site_url(),
            'version'  => $this->plugin_version(),
        ], $key, $secret);

        $state = $this->state();

        // ---- 1) 服务器说不清楚 → 宽限期（V3 §十八 前四行）----
        if ($res['http'] === null
            || $res['http'] >= 500
            || $res['http'] === 429
            || AT8SDK_Response::error_code($res) === 'AT8_RATE_LIMITED') {
            $this->enter_grace($state, $res['http'] === null
                ? '授权服务器暂时无法访问，已进入宽限期'
                : '验证请求受限（HTTP ' . (int) $res['http'] . '），已进入宽限期');
            return;
        }

        $code = AT8SDK_Response::error_code($res);

        // ---- 2) Activation Secret 认证失败 → 回退 License Key 重试一次 ----
        // 整改 V3 §十一：这是设计上的回退，场景 B（secret 被服务端轮换）。
        // 注意回退**不能**绕过服务端的 Activation / Domain / License 状态检查
        // ——服务端对 License Key 路径同样要求域名已绑定、授权 active，
        // 所以 License Key 回退最多只能恢复"secret 过期"这一种情况；
        // 授权真被吊销时（场景 C）Key 路径同样失败，最终仍进 revoked。
        if ($code === 'AT8_AUTH_FAILED' || $code === 'AT8_SIGNATURE_INVALID') {
            if ($secret !== '') {
                $retry = $this->client->post('/license/verify', [
                    'product'  => $this->config->product,
                    'site_url' => $this->config->site_url(),
                    'version'  => $this->plugin_version(),
                ], $key);

                $d = AT8SDK_Response::unwrap($retry, AT8SDK_Response::required_for_license());
                if ($d !== null) {
                    $this->save_state($this->build_state($d, $key, 'active'));
                    return;
                }

                // 回退也失败：网络/限流仍走宽限期，其余按明确拒绝处理
                if ($retry['http'] === null
                    || $retry['http'] >= 500
                    || $retry['http'] === 429
                    || AT8SDK_Response::error_code($retry) === 'AT8_RATE_LIMITED') {
                    $this->enter_grace($state, '授权服务器暂时无法访问，已进入宽限期');
                    return;
                }

                $res  = $retry;
                $code = AT8SDK_Response::error_code($res);
            }
        }

        // ---- 3) 成功（严格判定 + schema 校验，V3 §九/§十）----
        $d = AT8SDK_Response::unwrap($res, AT8SDK_Response::required_for_license());
        if ($d !== null) {
            $this->save_state($this->build_state($d, $key, 'active'));
            return;
        }

        // ---- 4) HTTP 200 但响应畸形 → 不能当成授权有效 ----
        // WAF / CDN / 错误页面返回 200 时会走到这里。
        // 按"服务器说不清楚"处理更安全：进宽限期而不是停 Pro，
        // 同时 message 明确说明异常，客户不会误以为自己被吊销。
        if ((int) $res['http'] >= 200 && (int) $res['http'] < 300 && $code === '') {
            $this->enter_grace($state, '授权服务器返回了无法理解的响应，已进入宽限期');
            return;
        }

        // ---- 5) 服务器明确拒绝 → 立即停 Pro，不进宽限期 ----
        $state['status']  = $this->map_error_status($code);
        $state['message'] = AT8SDK_Response::error_message($res);
        $this->save_state($state);
    }

    /* ---------------- 内部工具 ---------------- */

    /**
     * 优先用 Activation Secret（激活后），否则用 License Key。
     */
    private function request($path, $body, $key, $secret)
    {
        if ($secret !== '') {
            return $this->client->post_with_secret($path, $body, $secret);
        }
        return $this->client->post($path, $body, $key);
    }

    /**
     * 由服务端响应构建本地状态。
     */
    private function build_state($d, $key, $status)
    {
        $old = $this->state();

        $state = [
            'key'                 => $key !== '' ? $key : (isset($old['key']) ? $old['key'] : ''),
            'status'              => $status,
            'server_status'       => isset($d['status']) ? (string) $d['status'] : $status,
            'license_type'        => isset($d['license_type']) ? $d['license_type'] : '',
            'plan'                => isset($d['plan']) ? $d['plan'] : '',
            'product'             => isset($d['product']) ? $d['product'] : $this->config->product,
            'products'            => isset($d['products']) && is_array($d['products']) ? $d['products'] : [],
            'lifetime'            => !empty($d['lifetime']),
            'expires_at'          => isset($d['expires_at']) ? (int) $d['expires_at'] : 0,
            'update_access_until' => isset($d['update_access_until']) ? (int) $d['update_access_until'] : 0,
            'support_until'       => isset($d['support_until']) ? (int) $d['support_until'] : 0,
            'update_access'       => !empty($d['update_access']),
            'support_access'      => !empty($d['support_access']),
            'max_sites'           => isset($d['max_sites']) ? (int) $d['max_sites'] : 1,
            'used_sites'          => isset($d['used_sites']) ? (int) $d['used_sites'] : 1,
            'dev_sites'           => isset($d['dev_sites']) ? (int) $d['dev_sites'] : 1,
            'grace_days'          => isset($d['grace_days']) ? (int) $d['grace_days'] : 7,
            'domain'              => isset($d['domain']) ? (string) $d['domain'] : '',
            'environment'         => isset($d['environment']) ? (string) $d['environment'] : '',
            'activation_id'       => isset($d['activation_id']) ? (int) $d['activation_id'] : (int) ($old['activation_id'] ?? 0),
            'server_version'      => isset($d['server_version']) ? (string) $d['server_version'] : '',
            'last_verified_at'    => time(),
            'next_check_at'       => isset($d['next_check_at']) ? (int) $d['next_check_at'] : time() + DAY_IN_SECONDS,
            'message'             => '',
        ];

        // 宽限期截止：以服务端下发为准，服务端没给才按本地 grace_days 兜底
        if (isset($d['grace_until'])) {
            $state['grace_until'] = (int) $d['grace_until'];
        }

        // Activation Secret：服务端一次性下发，仅在返回时更新；后续响应不再重复携带
        if (!empty($d['activation_secret'])) {
            $state['activation_secret'] = (string) $d['activation_secret'];
        } elseif (!empty($old['activation_secret'])) {
            $state['activation_secret'] = $old['activation_secret'];
        }

        return $state;
    }

    /**
     * 进入宽限期（整改 V3 §十九/§二十）。
     *
     * 关键：**grace_until 不能由本次失败决定**，必须从
     * 「上次成功验证时间 + grace_days」算起。否则每次断网都会
     * 把宽限期顺延，客户只要一直断网就能无限续命。
     *
     * 防篡改（§二十）：last_verified_at 存在带 HMAC 签名的 state 里，
     * 客户直接改 wp_options 会导致签名不匹配，is_valid() 一律返回 false。
     * 就算绕过签名，expires_at 的本地判定仍然独立生效。
     */
    private function enter_grace($state, $message)
    {
        // 起点必须是上次**成功**验证的时刻；没有过成功记录则不给宽限期
        $last_ok = isset($state['last_verified_at']) ? (int) $state['last_verified_at'] : 0;
        if ($last_ok <= 0) {
            // 从未成功验证过 → 无宽限期可言，立即失效
            $state['status']  = 'server_unavailable';
            $state['message'] = $message;
            unset($state['grace_until']);
            $this->save_state($state);
            return;
        }

        $grace_days = isset($state['grace_days']) ? max(1, (int) $state['grace_days']) : 7;
        $grace_until = $last_ok + $grace_days * DAY_IN_SECONDS;

        // 已经超过宽限期上限：不再续期，直接判定失效
        if ($grace_until <= time()) {
            $state['status']      = 'grace';
            $state['grace_until'] = $grace_until;
            $state['message']     = '宽限期已结束，且授权服务器持续无法访问。恢复连接后将自动重新验证。';
            $this->save_state($state);
            return;
        }

        $state['status']      = 'grace';
        $state['grace_until'] = $grace_until;
        $state['message']     = $message;
        $this->save_state($state);
    }

    /**
     * 统一错误码 → 本地状态。新旧码都识别。
     */
    private function map_error_status($code)
    {
        switch ((string) $code) {
            // 新版统一码
            case 'AT8_LICENSE_EXPIRED':    return 'expired';
            case 'AT8_LICENSE_REVOKED':    return 'revoked';
            case 'AT8_LICENSE_SUSPENDED':  return 'suspended';
            case 'AT8_LICENSE_CANCELLED':  return 'cancelled';
            case 'AT8_LICENSE_INVALID':
            case 'AT8_PRODUCT_INVALID':
            case 'AT8_DOMAIN_MISMATCH':
            case 'AT8_ACTIVATION_LIMIT':
            case 'AT8_AUTH_FAILED':
            case 'AT8_SIGNATURE_INVALID':
            case 'AT8_NONCE_REPLAY':
            case 'AT8_TIMESTAMP_EXPIRED':  return 'invalid';
            case 'AT8_SERVER_UNAVAILABLE': return 'server_unavailable';
            case 'AT8_RATE_LIMITED':       return 'grace';

            // v1 旧码（迁移期兼容）
            case 'LICENSE_EXPIRED':        return 'expired';
            case 'LICENSE_REVOKED':        return 'revoked';
            case 'LICENSE_SUSPENDED':      return 'suspended';
            case 'LICENSE_CANCELLED':      return 'cancelled';
            case 'LICENSE_NOT_FOUND':
            case 'LICENSE_INVALID':
            case 'PRODUCT_MISMATCH':
            case 'DOMAIN_NOT_AUTHORIZED':
            case 'SITE_LIMIT_REACHED':
            case 'INVALID_SIGNATURE':
            case 'DUPLICATE_NONCE':
            case 'INVALID_TIMESTAMP':      return 'invalid';
            case 'RATE_LIMITED':           return 'grace';

            default:                       return 'auth_failed';
        }
    }

    /**
     * Site Identifier：首次激活生成后固定不变（§7）。
     * 用于识别"同一站点的不同副本"，不随 URL 变化。
     */
    private function site_identifier()
    {
        $opt = 'at8sdk_site_id';
        $sid = get_option($opt, '');
        if (!is_string($sid) || !preg_match('/^[a-z0-9._-]{8,64}$/', $sid)) {
            try {
                $sid = 'w' . bin2hex(random_bytes(12));
            } catch (Exception $e) {
                $sid = 'w' . substr(md5(uniqid('', true)), 0, 24);
            }
            update_option($opt, $sid, true);
        }
        return $sid;
    }

    /** Multisite 子站编号（非 multisite 返回 null） */
    private function multisite_sub()
    {
        if (!is_multisite()) {
            return null;
        }
        $blog_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
        return $blog_id > 0 ? $blog_id : 0;
    }

    private function plugin_version()
    {
        if (!$this->config->plugin_file) {
            return '';
        }
        $data = get_file_data($this->config->plugin_file, ['Version' => 'Version']);
        return isset($data['Version']) ? $data['Version'] : '';
    }
}

} // class_exists guard