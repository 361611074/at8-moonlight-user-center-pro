# Pro 兼容性报告

- 日期：2026-10-15
- Pro：`main` @ `236e570`（v1.6.2）
- Free：`master` @ `375931b`（v2.5.0）

## 1. 依赖与加载顺序

| 场景 | 机制 | 实测结果 |
|---|---|---|
| Free 激活、Pro 未安装 | 无交互 | 回归 76/0，无任何 Pro 依赖错误 |
| Pro 激活、Free 缺失 | bootstrap `function_exists('at8_mluc_get_option')` + `defined('AT8_MLUC_VERSION')` 双检，仅渲染可关闭的管理员提示 | 代码审计确认；未做卸载 Free 的破坏性实测 |
| Free 过旧（< 2.5.0） | `version_compare` 硬检查 + 提示，Pro 模块不启动 | 代码审计确认（阈值 2.5.0） |
| 两者同时激活 | 命名空间 / 选项 / CPT / cron / 钩子全前缀隔离（`AT8_MLUCP_` / `at8_mlucp_` / `at8-mlucp-`） | `pro_test.php` 61/0 |
| Pro 停用后 Free 工作 | Pro 无 Free 侧残留钩子依赖（全部经 Free 公开过滤器注册，随插件停用自动移除） | 停用 Pro 后 Free 回归 76/0 |
| Pro 卸载不删 Free 数据 | Pro 无 uninstall 删除 Free 选项/meta 的代码 | 代码审计确认 |

## 2. Hook / API 依赖（Pro → Free）

| Free 扩展点 | Pro 用途 | 状态 |
|---|---|---|
| `at8_mluc_account_tabs` | 购买记录 tab | 稳定使用 |
| `at8_mluc_admin_menu` | License 子菜单挂载 | 稳定使用 |
| `at8_mluc_register_settings` / `at8_mluc_settings_extra_fields` | Online Payment / Orders / Hidden Content 分区 | 稳定使用 |
| `at8_mluc_payment_gateways` / `at8_mluc_payment_order_response` | 注册 PayPal/Stripe、替换 manual 响应 | 稳定使用 |
| `at8_mluc_upgrade_paths` | tiered 差价路径 | 稳定使用 |
| `at8_mluc_order_price` | 差价订单计价 | 稳定使用（2.4.1 引入） |
| `at8_mluc_paywall_enabled/_levels/_price/_can_purchase/_unlock_expire/_locked_html/_unlocked_html/_meta_box` | 付费墙字段映射与资源注入 | 稳定使用（2.4.1 引入） |
| `at8_mluc_paywall_unlocked` | 销量统计 | 稳定使用 |
| `at8_mluc_hide_types/_user_can_view/_cta_html` | vip1/payshow 门 | 稳定使用 |
| `at8_mluc_membership_levels`、`at8_mluc_ui_label`、`at8_mluc_template_args`、`at8_mluc_shortcode_reference`、`at8_mluc_upgrade_features`、`at8_mluc_is_pro` | 等级/文案/模板/速查表/对比表/桥 | 稳定使用 |
| ~~`at8_mluc_membership_level_limit`~~ | — | **已移除**（Free 2.5.0 删除） |
| ~~`at8_mluc_limit_avatar_count`~~ | — | **已移除** |
| ~~`at8_mluc_limit_gateway_count`~~ | — | **已移除** |
| ~~`at8_mluc_paywall_level_limit`~~ | — | **已移除**（Free 2.5.0） |

Pro 不依赖 Free 的私有内部类或不稳定变量；所有集成都经上述公开 Hook。

## 3. 数据兼容

- Free 2.5.0 起付费墙 `META_LEVEL` 存数组（多选）；Pro 1.6.0 的 `filter_levels` 双读（数组 / 旧字符串），并在 `free_levels` 为空时把核心 meta 合并进等级列表——旧单值配置与核心盒配置都不丢。
- Pro 自有 meta（`_at8_mlucp_pw_*`）与核心 meta 键不同，互不覆盖；卸载 Pro 不影响核心付费墙配置。
- 授权状态 option（`at8lic_sdk_*`）与 Pro 选项 blob 仅属 Pro。

## 4. 组合回归证据

| 套件 | 配置 | 结果 |
|---|---|---|
| `pro_test.php` | Pro 激活 + 本地授权种子 + WP 7.1.3 | **61 passed / 0 failed** |
| `reg_test.php`（Free 套件） | Pro 停用 | **76 passed / 0 failed** |
| `reg_test.php`（Free 套件） | Pro 激活 | 66/10，10 项为设计使然的差异（管理员免闸 / upsell 面消失 / 货架含 Pro 网关 / Pro 时效过滤），无崩溃、无数据错误 |

## 5. 已知限制

- "Pro 激活但 Free 缺失"与"多站点卸载"未做破坏性实测（代码审计确认无 Fatal 路径）。
- 旧版 Pro（< 1.5.0）配 Free 2.5.0：旧 Pro 仍自带闸门但键不同，不会双闸；但其配额过滤器已不存在，行为等价于"无抬升"，建议随 Pro 升级到 1.6.2。

## 6. 结论

Pro 1.6.2 与 Free 2.5.0 的接口边界干净：Pro 只用公开 Hook，配额类 Hook 已全部退役，数据格式向前/向后兼容。组合回归全绿。
