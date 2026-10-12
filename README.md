# AT8 Moonlight User Center Pro (at8-moonlight-user-center-pro)

「AT8 Moonlight User Center」（Free 版）的商业化扩展插件。本仓库即插件根目录，上传后作为**独立插件**在 WordPress 后台启用；需先安装并启用 Free 版。

- 版本：1.0.0
- 授权：GPL-2.0-or-later
- 依赖：**必须先安装并启用** AT8 Moonlight User Center（Free 版）2.0.0+
- 作者：漫步白月光 · https://www.at8.fun/

## Pro 功能

| 模块 | 说明 |
| --- | --- |
| 会员等级 | 后台动态定义等级（标签/颜色/价格/有效期/排序），到期自动降级；用户资料页可手工设置等级与到期日 |
| 账户中心会员 Tab | 当前等级 + 等级对照表 + 购买/升级卡（挂在 Free 的 `at8_mluc_account_tabs` 扩展点） |
| 付费墙 Paywall | 单篇文章/页面付费解锁，一次性购买，可选有效期；含销量统计与 REST 输出过滤 |
| 隐藏内容 `[hidecontent]` | 登录可见 / 评论可见 / 会员等级可见 / 付费可见 四种短代码 |
| 支付 | 线下转账（管理员确认收款）+ PayPal（Smart Buttons）+ Stripe（Checkout + Webhook 兜底） |
| 订单 | CPT `at8_mlucp_order`，pending 超时自动关闭，管理员一键确认收款并开通 |
| 购买记录 | 账户中心 tab + `[at8_mlucp_purchases]` 短代码 |
| 演示视频 | CPT `at8_mlucp_demo_video`，按会员等级控制可见性，`[at8_mlucp_videos]` / `[at8_mlucp_video]` 短代码 |
| 素材下载 | CPT `at8_mlucp_material`，按等级控制，下载限次，`[at8_mlucp_materials]` / `[at8_mlucp_downloads]` 短代码 |
| 社交登录 | QQ / 微信 / 微博 / GitHub / Google / Microsoft / Facebook / Apple，按钮挂在 Free 登录/注册表单底部 |
| Elementor | 登录 / 注册 / 个人资料三个小工具 |
| 编辑器按钮 | 文章编辑器「隐藏内容」下拉，快速插入四种 hidecontent 短代码 |

## 授权体系

两种模式，在「用户中心 → Pro 设置 → License 服务器」切换：

1. **离线模式（默认）**：Key 自身携带签名（`AT8ML-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-SIGN`，前六段为 base32 编码的紧凑负载 `mlucp|到期时间戳`，末段为 HMAC-SHA256 签名前缀）。全程本地校验，不发任何外部请求。方便线下交付；需如实告知：与所有纯本地方案一样，签名可被逆向提取，防不住针对性破解。
2. **服务器模式**：填写授权服务器地址后，激活 / 校验 / 更新全部走 REST（Key 只放 POST body，不进 URL）。校验结果缓存 12 小时；**服务器不可达 ≠ 授权失效**——进入 14 天宽限期，期间 Pro 功能照常，只有服务端明确吊销才立即停用。

授权服务器（`at8-license-server`）提供 `activate` / `deactivate` / `validate` / `update` / `package` 端点，后续单独交付。

## 与 Free 的边界

- Pro 只通过 Free 的公开钩子交互：`at8_mluc_account_tabs`（挂 tab）、`at8_mluc_login_form_bottom` / `at8_mluc_register_form_bottom`（挂社交登录按钮）、`at8_mluc_is_pro`（授权总开关）。
- Free 缺失时 Pro 只弹后台提示，不产生致命错误；授权失效时仅保留 License 与设置两个页面，商业模块完全休眠。
- 类前缀 `AT8_MLUCP_`、函数前缀 `at8_mlucp_`、选项 `at8_mlucp_options`、CPT `at8_mlucp_*`、文本域 `at8-moonlight-user-center-pro`，与 Free 完全隔离，可独立启停。
- Free 未授权、未安装的任何情况下都完整可用（登录、注册、账户中心、头像、菜单）。

## 安装

1. 确认「AT8 Moonlight User Center」已启用；
2. 将本仓库内容上传至 `wp-content/plugins/at8-moonlight-user-center-pro/`；
3. 启用本插件，进入「用户中心 → License（Pro）」输入 License Key 激活；
4. 「用户中心 → Pro 设置」配置会员等级与支付网关。

## License Key 获取

- 从 at8.fun 购买后获得绑定站点的 Key；
- 迁移域名前先在 License 页停用，再在新站点激活。

## 目录结构

```
at8-moonlight-user-center-pro/
├── at8-moonlight-user-center-pro.php   主文件（Update URI 指向自有更新通道）
├── includes/
│   ├── functions.php                   Pro 辅助函数 + Free 桥接
│   ├── class-license.php               授权（离线签名 + 远程 REST 双模式）
│   ├── class-updater.php               自建更新通道
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
├── uninstall.php                       多站点安全清理
└── readme.txt
```

## License

GPL-2.0-or-later。Key 的签名与授权服务器实现不属于插件分发内容。
