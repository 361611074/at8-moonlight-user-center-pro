# AT8 Moonlight User Center Pro (at8-moonlight-user-center-pro)

「AT8 Moonlight User Center」（Free 版）的商业化扩展插件。本仓库即插件根目录，上传后作为**独立插件**在 WordPress 后台启用；需先安装并启用 Free 版。

- 版本：1.2.0
- 授权：GPL-2.0-or-later
- 依赖：**必须先安装并启用** AT8 Moonlight User Center（Free 版）2.0.0+
- 作者：漫步白月光 · https://www.at8.fun/

## Pro 功能

| 模块 | 说明 |
| --- | --- |
| 会员等级 | 后台动态定义等级（标签/颜色/价格/有效期/排序），到期自动降级；用户资料页可手工设置等级与到期日 |
| 账户中心会员 Tab | 当前等级 + 等级对照表 + 购买/升级卡（挂在 Free 的 `at8_mluc_account_tabs` 扩展点） |
| 付费墙 Paywall | 单篇文章/页面付费解锁，一次性购买，可选有效期；含销量统计与 REST 输出过滤 |
| 隐藏内容扩展 | 会员等级 / 付费 两型门控（登录/评论两型在 Free，统一短码 `[at8_mluc_hide]`） |
| 支付 | 线下转账（管理员确认收款）+ PayPal（Smart Buttons）+ Stripe（Checkout + Webhook 兜底） |
| 订单 | CPT `at8_mlucp_order`，pending 超时自动关闭，管理员一键确认收款并开通 |
| 购买记录 | 账户中心 tab + `[at8_mlucp_purchases]` 短代码 |
| 演示视频 | CPT `at8_mlucp_demo_video`，按会员等级控制可见性，`[at8_mlucp_videos]` / `[at8_mlucp_video]` 短代码 |
| 素材下载 | CPT `at8_mlucp_material`，按等级控制，下载限次，`[at8_mlucp_materials]` / `[at8_mlucp_downloads]` 短代码 |
| （已下沉 Free） | 社交登录、Elementor 小工具、登录/评论隐藏内容、编辑器按钮自 1.2.0 起移入 Free 2.1.0 |
| 编辑器按钮（Pro 部分） | 文章编辑器「隐藏内容」下拉的会员/付费两型（登录/评论两型在 Free） |

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

- Pro 只通过 Free 的公开钩子交互：`at8_mluc_account_tabs`（挂 tab）、`at8_mluc_login_form_bottom` / `at8_mluc_register_form_bottom`（挂社交登录按钮）、`at8_mluc_is_pro`（授权总开关）。
- Free 缺失时 Pro 只弹后台提示，不产生致命错误；授权失效时仅保留 License 与设置两个页面，商业模块完全休眠。
- 类前缀 `AT8_MLUCP_`、函数前缀 `at8_mlucp_`、选项 `at8_mlucp_options`、CPT `at8_mlucp_*`、文本域 `at8-moonlight-user-center-pro`，与 Free 完全隔离，可独立启停。
- SDK 类前缀 `AT8SDK_` / `AT8_License_SDK`，自带 class_exists 守卫，多个 AT8 产品可并存。
- Free 未授权、未安装的任何情况下都完整可用（登录、注册、账户中心、头像、菜单）。

## 安装

1. 确认「AT8 Moonlight User Center」已启用；
2. 将本仓库内容上传至 `wp-content/plugins/at8-moonlight-user-center-pro/`；
3. 启用本插件，进入「用户中心 → License（Pro）」输入授权中心签发的授权码激活；
4. 「用户中心 → Pro 设置」配置会员等级与支付网关。

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
│   ├── class-settings.php              Pro 设置页（挂在 Free 菜单下）
│   ├── class-activator.php             激活/停用
│   ├── class-membership.php            会员等级系统
│   ├── class-membership-tab.php        账户中心会员 Tab
│   ├── class-payments.php              订单 + 线下转账 + 购买流程
│   ├── class-paypal.php / class-stripe.php
│   ├── class-paywall.php               文章付费墙
│   ├── class-hidecontent.php           隐藏内容短代码
│   ├── class-video.php / class-material.php
│   ├── class-purchases.php             购买记录
│   ├── class-oauth.php                 社交登录
│   ├── class-elementor.php / class-elementor-widgets.php
│   └── class-editor-button.php         TinyMCE 按钮
├── templates/                          account-membership / membership-purchase / paywall-meta
├── assets/                             at8-mlucp.css / at8-mlucp.js / at8-mlucp-admin.js / at8-mlucp-pw-admin.js / at8-mlucp-tinymce.js
├── languages/
├── uninstall.php                       多站点安全清理（含 SDK 状态）
└── readme.txt
```

## License

GPL-2.0-or-later。`sdk/` 目录为自研构建产物，随插件分发。
