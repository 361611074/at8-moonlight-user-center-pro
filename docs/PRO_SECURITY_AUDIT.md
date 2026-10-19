# Pro 安全审计报告

- 日期：2026-10-15
- 仓库：`github.com/361611074/at8-moonlight-user-center-pro`
- 分支 / commit：`main` @ `236e570`（v1.6.2）
- 依赖：Free 2.5.0+（bootstrap `version_compare` 硬检查，缺失/过旧仅出管理员提示，不 Fatal）
- 结论：商业扩展自身安全边界清晰；**不作为 WordPress.org Free 插件包提交**，站外分发。

## 1. 许可证门控范围（P0 审计项）

| 模块 | 门控点 | 失效时行为 |
|---|---|---|
| PayPal / Stripe 网关注册与订单响应 | `class-gateways.php:71,96,184`（`AT8_MLUCP_License::is_active()`） | 网关不注册；Free 的线下转账不受影响 |
| paywall 富卡片 / 资源 / 差价路径 / hidecontent Pro 类型 / 视频 / 素材 / 购买记录 | 同一 bootstrap 门控（无授权只实例化 Settings 提前 return） | 全部停摆；Free 付费墙核心继续按核心 meta 工作 |
| Free 数据 | 无任何授权分支写 Free 数据 | 授权失效不改/不删 Free 的用户、等级、订单、解锁记录 |

`at8_mluc_is_pro` 桥只翻转布尔供 Free 的 UI/文案使用，不解锁任何 Free 功能（Free 2.5.0 起已无配额可解）。

## 2. 自动更新与下载完整性（P0 审计项）

`sdk/includes/class-updater.php`：

| 检查 | 实现 | 结论 |
|---|---|---|
| 下载地址协议 | 强制 HTTPS，否则拒绝（`at8sdk_insecure_url`） | 通过 |
| 重定向 | `redirection => 0`，3xx 一律拒绝 | 通过（防止被指向任意主机） |
| 证书校验 | `sslverify => true`，SSL 错误单独分类并写日志 | 通过 |
| 完整性 | 服务端下发 sha256，`hash_equals` 校验，格式 `^[a-f0-9]{64}$` 预校验，失败删临时文件 | 通过 |
| 令牌 | 401/403/410 → 令牌失效路径（换 token 重试一次） | 通过 |
| 临时文件 | `wp_tempnam` + 写完整性校验，失败清理 | 通过 |
| 包来源 | `product_from_package()` 三态判定：非 AT8 包原样放行 / AT8 scheme 非法 marker 明确报错 | 通过 |
| 失败保留 | 任一步骤失败不删除已安装 Pro | 通过（代码路径无删除调用） |
| 敏感信息 | 错误信息不含密钥/令牌；日志仅产品名与原因 | 通过 |

**未执行**：对授权服务器的真实故障注入（超时 / 证书错误 / 篡改摘要），仅代码审计。宽限期与每日复查逻辑见 `sdk/includes/class-scheduler.php`。

## 3. 支付安全（P0 审计项）

### Stripe（`class-stripe.php`）

- Checkout Session 服务端创建；金额 / 币种 / 商品 / 会员期限来自服务端订单 meta，不信任前端。
- Webhook：`stripe-signature` HMAC-SHA256 验签（`hash_equals`）+ 5 分钟时间容差 + `livemode` 与密钥模式一致性 + **服务端回查 Session**（未配置 secret 时同样防伪造）。
- 回跳成功 URL 不单独作为已支付依据；`complete_order` 原子认领保证重复 webhook / 回跳不重复发放。
- Secret / Webhook Secret 仅存设置项（password 字段），不出现在任何输出。

### PayPal（`class-paypal.php`）

- 服务端 create + capture；校验 `custom_id === 本地订单 ID`、capture 金额与订单金额一致（±0.01）。
- 订单归属 / 状态 / 网关三重校验（`check_order`）；重复 capture 走幂等认领。
- 不信任前端传来的 capture 成功、价格、用户 ID。

### 线下转账（Free 核心）

- 管理员确认检查 capability + nonce + 订单当前状态；`claim_order()` 单条 UPDATE 原子转换。

### 已知限制（如实披露）

- Stripe webhook 在**未配置** `stripe_webhook_secret` 时跳过签名校验（仍回查 API），建议文档引导配置 secret（设置项已存在并在界面上说明）。

## 4. 其他安全项

| 项 | 结论 |
|---|---|
| 下载端点 | transient 令牌 + 二次解锁校验 + 归属校验；本地附件流式 / 外链 302，无可预测公开 URL |
| 付费内容泄露面 | 正文由 Free 核心闸门负责（feed 已封堵）；REST 经 `rest_prepare` 单独封堵；视频锁定态不输出真实地址 |
| 输入 / 输出 | 全部 `wp_unslash` 后按类型清理；输出 `esc_html/esc_attr/esc_url/wp_kses_post` |
| SQL | 仅 `$wpdb->prepare`（SDK 与 Stripe webhook 路径） |
| 硬编码密钥 | 无（密钥走设置项；本地授权种子只在测试站，不进仓库与发布包） |
| 繁体字符串混杂 | 1.6.0 已统一简体 |

## 5. 结论

Pro 的许可证、更新下载、支付三条高危路径均满足"验证来源、验证完整性、不信任前端、失败安全"的要求；授权失效只停 Pro 自身。剩余风险：webhook secret 未配置时的降级路径（已披露）、网络故障注入未实测。Pro **不提交 WordPress.org**，作为站外商业扩展分发。
