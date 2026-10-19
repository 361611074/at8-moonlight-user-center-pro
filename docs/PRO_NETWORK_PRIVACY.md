# Pro 网络通信与隐私披露

- 日期：2026-10-15
- 仓库：`at8-moonlight-user-center-pro` @ `236e570`（v1.6.2）
- 说明：本文件列出 Pro 插件**全部**外部 HTTP 通信。Free 插件的通信（仅社交登录提供商）在 Free 仓库 `docs/FREE_PRO_BOUNDARY.md` 第 3 节披露；两部分合起来才是产品家族的全貌，Free readme 不得声称"整个产品系列无外部通信"。

## 1. 通信清单

| # | 目的 | 对端 | 触发条件 | 方法 |
|---|---|---|---|---|
| 1 | 许可证激活 / 校验 / 解绑 | `https://www.at8.fun`（AT8 License Server v2） | 激活时、每日复查 cron、状态变化 | POST（`sdk/includes/class-client.php`） |
| 2 | 更新检查 | 同上 | 每日 cron / 手动 | POST |
| 3 | 更新包下载 | 授权服务器下发的 HTTPS 地址 | 管理员确认更新 | GET（禁跳转、强制证书、sha256 校验） |
| 4 | PayPal OAuth token / 下单 / 捕获 | `https://api-m.paypal.com` 或沙箱 | 用户用 PayPal 支付 | POST（`class-paypal.php`） |
| 5 | Stripe Checkout Session 创建 / 查询 | `https://api.stripe.com` | 用户用 Stripe 支付 | POST / GET（`class-stripe.php`） |
| 6 | Stripe Webhook（入站） | Stripe → 本站 REST `at8-mlucp/v1/stripe-webhook` | Stripe 事件推送 | 入站，HMAC 验签 |

无其他外发请求：无遥测、无分析、无字体/CDN、无远程可执行代码。

## 2. 发送字段

| 通信 | 字段 | 用途 |
|---|---|---|
| 许可证 / 更新 | 站点域名、站点 URL、产品 slug（`at8-moonlight-user-center-pro`）、版本号、许可证令牌、授权动作 | 授权绑定、站点数校验、更新权益 |
| PayPal / Stripe | 订单 ID、金额、币种、商品描述、会员期限、成功/取消回跳 URL | 支付下单 |
| Webhook | Stripe 事件载荷（本站订单 ID、Session ID、金额、livemode） | 兜底开通（回查 API 后使用） |

不发送：用户资料、订单明细以外的访客行为、与授权/支付无关的任何数据。

## 3. 许可证令牌的传输与存储

- 传输：仅 HTTPS POST 至授权服务器；不进入页面、Referer、普通日志。
- 存储：WordPress option（`at8lic_sdk_*_state`），签名状态串；设置界面密码字段不外显。
- 解绑 / 删除：解绑动作通知服务器；删除插件按 Free/Pro 的卸载规则清理（Pro 卸载不删 Free 数据）。

## 4. 失败与超时处理

| 场景 | 行为 |
|---|---|
| 授权服务器不可达 | 按已公开宽限期策略保持现状；不误清授权、不动业务数据（`class-scheduler.php`） |
| 证书错误 / 超时 | 分类处理并写错误日志（仅产品名与原因，不含令牌） |
| 非法 JSON / 错误产品 ID | 拒绝并阻止更新 |
| 下载 401/403/410 | 令牌失效路径：换 token 重试一次，再失败明确报错 |
| 下载被篡改（sha256 不符） | 删除临时文件，拒绝安装，保留现有 Pro |
| Stripe 签名错误 / 过期 | 400 拒绝，不发放 |

## 5. 站点数 / 域名绑定

- 授权服务器按域名绑定与站点数校验；换域名 / 超站点数由服务端策略决定（本地插件只呈现结果，不绕过）。

## 6. 未执行项

- 授权服务器故障注入（超时 / 证书错误 / 返回篡改摘要）的真实网络实测：**未执行**（代码路径已逐条审计，见 `PRO_SECURITY_AUDIT.md` 第 2 节）。
- 支付沙箱端到端：**未执行**（无凭证）。
