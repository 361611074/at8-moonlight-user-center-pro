# AT8 Moonlight User Center Pro (at8-moonlight-user-center-pro)

「AT8 Moonlight User Center」（Free 版）的商业化扩展插件。本仓库即插件根目录，上传后作为**独立插件**在 WordPress 后台启用；需先安装并启用 Free 版。

- 版本：1.6.2
- 授权：GPL-2.0-or-later
- 依赖：**必须先安装并启用** AT8 Moonlight User Center（Free 版）2.5.0+
- 作者：漫步白月光 · https://www.at8.fun/

## Pro 功能

| 模块 | 说明 |
| --- | --- |
| 会员等级扩容 | 等级上限从 Free 的 3 级提升到 8 级（`at8_mluc_membership_level_limit` 过滤器）；等级定义/购买卡/会员 Tab 均在 Free，Pro 只放大数量 |
| 在线支付 | PayPal（Smart Buttons，服务端 capture）+ Stripe（Checkout + Webhook 兜底）；订单引擎、线下转账与会员开通在 Free，Pro 通过 `at8_mluc_payment_gateways` / `at8_mluc_payment_order_response` 注入 |
| 订单管理分区 | 在 Free 的「用户中心 → 设置」屏上渲染 Online Payment 与 Orders 两个分区（`at8_mluc_register_settings` action） |
| 付费墙 Paywall | 单篇文章/页面付费解锁，一次性购买，可选有效期；订单复用 Free 的 `at8_mluc_order` CPT，经 `at8_mluc_complete_paywall_order` 原子交货 |
| 隐藏内容扩展 | 会员等级 / 付费 两型门控（登录/评论两型在 Free，统一短码 `[at8_mluc_hide]`） |
| 购买记录 | 账户中心 tab + `[at8_mlucp_purchases]` 短代码 |
| 演示视频 | CPT `at8_mlucp_demo_video`，按会员等级控制可见性，`[at8_mlucp_videos]` / `[at8_mlucp_video]` 短代码 |
| 素材下载 | CPT `at8_mlucp_material`，按等级控制，下载限次，`[at8_mlucp_materials]` / `[at8_mlucp_downloads]` 短代码 |
| 编辑器按钮（Pro 部分） | 文章编辑器「隐藏内容」下拉的会员/付费两型（登录/评论两型在 Free） |
| （已下沉 Free） | 会员等级系统、订单引擎、线下转账、社交登录、Elementor 小工具、登录/评论隐藏内容自 1.3.0 起全部移入 Free 2.2.0 |
| 旧链接兜底 | 已废弃的 `at8-mlucp-settings`（≤1.2.0 的 Pro 设置页）重定向到 Free 设置屏，挂在 `admin_page_access_denied`（core 渲染 403 前唯一可用的钩子） |

## 授权体系（1.1.0 起：AT8 License SDK）

授权全部交给内嵌的 **AT8 License SDK**（`sdk/` 目录，原样嵌入、不做修改），对接 **at8-license-server-v2** 授权中心（www.at8.fun）：

- 产品 slug：`at8-moonlight-user-center-pro`（授权中心后台「产品」须与此一致）
- 授权码格式 `AT8-XXXXX-XXXXX-XXXXX-XXXXX`，购买后从授权中心获得
- 激活 / 域名绑定 / 站点数限制 / 每日复查（WP-Cron）/ 宽限期 / 解绑 / 域名转移，全部由 SDK 处理
- `is_valid()` 只读本地状态（带 HMAC 签名，防数据库篡改），页面加载零 HTTP 请求
- 授权中心不可达 ≠ 授权失效：进入宽限期，Pro 照常；明确吊销 / 过期才停用，数据保留
- 授权制自动更新：授权有效且套餐含更新权益时，后台出现更新提示；下载走 SDK 托管通道（短时令牌 + SHA-256 完整性校验）
- 本地开发环境（localhost / *.local / *.test）激活不占正式席位（套餐另含 dev_sites 额度）
- 授权页位置不变：「用户中心 → License（Pro）」，由 SDK 自动注册

**1.0.x 迁移说明**：旧离线签名 Key（`AT8ML-…`）不再被识别，需在授权中心重新发码激活；旧授权记录保留但仅用于弹出一次性迁移提示，删除插件时清理。

## 与 Free 的边界

- Pro 只通过 Free 的公开钩子交互：`at8_mluc_account_tabs`（挂 tab）、`at8_mluc_is_pro`（授权总开关）、`at8_mluc_admin_menu`（**推荐的后台菜单挂载点**，保证 `add_submenu_page()` 算出正确的 page hook）、`at8_mluc_register_settings` / `at8_mluc_settings_extra_fields`（在 Free 设置屏上渲染自己的分区）、`at8_mluc_payment_gateways` / `at8_mluc_payment_order_response`（在线网关）、`at8_mluc_complete_paywall_order`（付费墙交货）、`at8_mluc_upgrade_features` / `at8_mluc_shortcode_reference`（校正 Free 设置屏上的对比表与短码速查）。Free 2.5.0 起免费版不再有任何人工上限，Pro 也不再有"放宽上限"类钩子（`at8_mluc_membership_level_limit` / `at8_mluc_limit_avatar_count` / `at8_mluc_limit_gateway_count` 已随 2.5.0 一并移除）。
- Free 缺失时 Pro 只弹后台提示，不产生致命错误；授权失效时仅保留 License 页面，商业模块完全休眠。
- 类前缀 `AT8_MLUCP_`、函数前缀 `at8_mlucp_`、选项 `at8_mlucp_options`、自有 CPT `at8_mlucp_*`、文本域 `at8-moonlight-user-center-pro`，与 Free 完全隔离，可独立启停。
- 会员数据（`at8_mluc_membership_level` / `at8_mluc_membership_expires`）与订单（CPT `at8_mluc_order`）归 Free 所有，Pro 停用不丢数据。
- 纵深防御：网关注册与订单响应在钩子层再次校验授权，仅有配置也不收款。
- 后台菜单顺序（1.3.1）：Authorization SDK 与第三方插件想把子菜单挂到「用户中心」下时，务必在 Free 的 `add_menu_page()` **之后**执行——用 Free 提供的 `at8_mluc_admin_menu`，或至少在 `admin_menu` 晚于优先级 5 的位置。挂早了会出现「菜单里有链接、点进去 403」。详见 Free 2.2.1 的变更说明。
- SDK 类前缀 `AT8SDK_` / `AT8_License_SDK`，自带 class_exists 守卫，多个 AT8 产品可并存。
- Free 未授权、未安装的任何情况下都完整可用（登录、注册、账户中心、头像、菜单、会员销售）。

## 变更记录（摘要）

完整 changelog 见 `readme.txt`。

- **1.6.2**：繁体文案统一为简体（素材/视频/Stripe）；metabox 输入统一 `wp_unslash`；远程文件下载重定向仅放行 http/https；readme 声明后台退款不自动回收权限；Stable tag 与发布版本对齐。
- **1.6.1**：对比表回调适配 Free 2.5.0 行键（按本机实际网关校正）；移除死默认项与过期文档描述。
- **1.6.0**：移除全部“放宽上限”集成（Free 2.5.0 已无任何上限），Pro 只贡献真实增量。

## 安装

1. 确认「AT8 Moonlight User Center」2.5.0+ 已启用；
2. 将本仓库内容上传至 `wp-content/plugins/at8-moonlight-user-center-pro/`；
3. 启用本插件，进入「用户中心 → License（Pro）」输入授权中心签发的授权码激活；
4. 会员等级 / 线下支付 / 订单在 Free 的「用户中心 → 设置」屏；在线网关（PayPal / Stripe）在设置屏的 Online Payment 分区。

## 授权码获取

- 从 at8.fun 购买后，授权中心自动发码（绑定产品与套餐）；
- 迁移域名前先在 License 页解绑本站，或直接使用「域名转移」；
- 授权中心后台：产品 → 套餐 → 上传 Pro 安装包（启用自动更新必需）。

## 目录结构

```
at8-moonlight-user-center-pro/
├── at8-moonlight-user-center-pro.php   主文件（SDK init + 模块启动）
├── sdk/                                AT8 License SDK（原样嵌入，勿改）
│   ├── at8-license-sdk.php
│   └── includes/                       Config / Client / License / Scheduler / AdminPage / Updater …
├── includes/
│   ├── functions.php                   Pro 辅助函数 + Free 桥接
│   ├── class-license.php               SDK 薄壳（is_active 门控 + 旧 Key 迁移提示）
│   ├── class-settings.php              Pro 选项注册与消毒（分区渲染在 Free 屏上）
│   ├── class-gateways.php              在线网关壳：注册 PayPal/Stripe + Online Payment/Orders 分区
│   ├── class-paypal.php / class-stripe.php
│   ├── class-paywall.php               文章付费墙
│   ├── class-hidecontent.php           隐藏内容短代码
│   ├── class-video.php / class-material.php
│   ├── class-purchases.php             购买记录
│   └── class-editor-button.php         TinyMCE 按钮
├── templates/                          paywall-meta
├── assets/                             at8-mlucp.css / at8-mlucp.js / at8-mlucp-pw-admin.js / at8-mlucp-tinymce.js
├── languages/
├── uninstall.php                       多站点安全清理（含 SDK 状态）
└── readme.txt
```

> 1.3.0 起：会员等级（class-membership.php）、订单引擎（class-payments.php）、会员 Tab
> （class-membership-tab.php）与相关模板已移入 Free 2.2.0，本插件不再包含。

## License

GPL-2.0-or-later。`sdk/` 目录为自研构建产物，随插件分发。
