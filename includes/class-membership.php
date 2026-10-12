<?php
/**
 * 会员等级系统：等级定义可由后台「会员等级定义」动态新增 / 编辑 / 删除。
 *
 * 存储：user meta at8_mlucp_membership_level (等级 key) + at8_mlucp_membership_expires (timestamp)。
 * 等级真相源：at8_mlucp_options['membership_levels']；为空时回退到本类工厂默认（含 free 等 5 级）。
 * 提供 helper 函数 at8_mlucp_user_level() / at8_mlucp_user_can_access() / at8_mlucp_user_is_expired()。
 *
 * @package AT8_Moonlight_User_Center_Pro
 */

if (!defined('ABSPATH')) {
    exit;
}

class AT8_MLUCP_Membership
{
    /** @var array<string,array<string,mixed>> */
    private static $levels = array(
        'free' => array(
            'label'       => 'Basic',
            'sort_order'  => 10,
            'expireable'  => false,
            'description' => 'Browse basic content and purchase paid materials.',
        ),
        'monthly' => array(
            'label'       => 'Monthly',
            'sort_order'  => 20,
            'expireable'  => true,
            'description' => 'Unlock monthly materials and demo videos.',
        ),
        'gold' => array(
            'label'       => 'Gold Member',
            'sort_order'  => 25,
            'expireable'  => true,
            'description' => 'Access advanced materials and worksheet downloads.',
        ),
        'premium' => array(
            'label'       => 'Premium',
            'sort_order'  => 30,
            'expireable'  => true,
            'description' => 'Unlock all premium materials, demo videos and worksheets.',
        ),
        'diamond' => array(
            'label'       => 'Diamond Member',
            'sort_order'  => 35,
            'expireable'  => true,
            'description' => 'Unlimited access to all site resources, including new content.',
        ),
    );

    public static function get_instance()
    {
        static $instance = null;
        if (null === $instance) {
            $instance = new self();
        }
        return $instance;
    }

    private function __construct()
    {
        add_action('show_user_profile', array($this, 'render_profile_fields'));
        add_action('edit_user_profile', array($this, 'render_profile_fields'));
        add_action('personal_options_update', array($this, 'save_profile_fields'));
        add_action('edit_user_profile_update', array($this, 'save_profile_fields'));
        // 订单付款后由 AT8_MLUCP_Payments::complete_order() 直接调用 grant_level()
        // 授予会员；第三方商城（at8-moonlight-shop）可通过
        // do_action( 'at8_mlucp_order_paid', $order_id, $user_id, $level ) 对接同一流程。
    }

    /**
     * 等级标识颜色（用于 UI 标签上色）。
     */
    public static function get_level_color($key)
    {
        $levels = self::get_levels();
        return isset($levels[$key]['color']) ? $levels[$key]['color'] : '';
    }

    /**
     * 等级购买价格（来自后台定义）。
     */
    public static function get_level_price($key)
    {
        $levels = self::get_levels();
        return isset($levels[$key]['price']) ? (float) $levels[$key]['price'] : 0;
    }

    /**
     * 等级有效期（天，0 = 永久）。
     */
    public static function get_level_validity($key)
    {
        $levels = self::get_levels();
        return isset($levels[$key]['validity']) ? (int) $levels[$key]['validity'] : 0;
    }

    /**
     * 普通会员（free 基座等级）是否启用。
     * 关闭后站点仅保留付费等级，未购买会员的用户不拥有任何等级；
     * 标记为 free 的公开内容仍对所有人可见（free 是内容可见性标签，不等同于会员身份）。
     */
    public static function free_enabled()
    {
        $on = function_exists('at8_mlucp_get_option') ? at8_mlucp_get_option('free_level_enabled', 1) : 1;
        return !empty($on);
    }

    /**
     * 全部等级定义（动态，真相源 = at8_mlucp_options['membership_levels']）。
     * 未配置时回退到工厂默认；始终保证 free 存在；按 sort_order 升序。
     *
     * @return array<string,array<string,mixed>>
     */
    public static function get_levels()
    {
        static $cache = null;
        if (null !== $cache) {
            return $cache;
        }
        $saved = function_exists('at8_mlucp_get_option') ? at8_mlucp_get_option('membership_levels', array()) : array();
        if (!is_array($saved) || empty($saved)) {
            // 后台未定义任何等级：使用插件内置工厂默认
            $levels = self::$levels;
        } else {
            $levels = array();
            foreach ($saved as $key => $lv) {
                if (!is_array($lv)) {
                    continue;
                }
                $key = sanitize_key($key);
                if ('' === $key) {
                    continue;
                }
                $validity = isset($lv['validity']) ? (int) $lv['validity'] : 0;
                $levels[$key] = array(
                    'label'       => isset($lv['label']) && '' !== (string) $lv['label'] ? $lv['label'] : $key,
                    'color'       => isset($lv['color']) ? $lv['color'] : (isset(self::$levels[$key]['color']) ? self::$levels[$key]['color'] : '#2f6fed'),
                    'price'       => isset($lv['price']) ? (float) $lv['price'] : 0,
                    'validity'    => $validity,
                    'sort_order'  => isset($lv['sort_order']) ? (int) $lv['sort_order'] : (isset(self::$levels[$key]['sort_order']) ? (int) self::$levels[$key]['sort_order'] : 50),
                    'expireable'  => ('free' === $key) ? false : ($validity > 0),
                    'description' => isset($lv['description']) ? (string) $lv['description'] : (isset(self::$levels[$key]['description']) ? self::$levels[$key]['description'] : ''),
                );
            }
            // 兜底：未提供 description 的等级用工厂默认（首次启用插件或新等级默认文案）
            foreach ($levels as $k => $lv) {
                if ((!isset($lv['description']) || '' === (string) $lv['description']) && isset(self::$levels[$k]['description'])) {
                    $levels[$k]['description'] = self::$levels[$k]['description'];
                }
            }
        }
        // 普通会员（free 基座）开关：开启时补回缺失的 free；关闭时移除（未付费用户视为无等级）
        if (self::free_enabled()) {
            if (!isset($levels['free'])) {
                $levels['free'] = self::$levels['free'];
            }
        } else {
            unset($levels['free']);
        }
        $levels = apply_filters('at8_mlucp_membership_levels', $levels);
        uasort($levels, function ($a, $b) {
            return ($a['sort_order'] ?? 0) - ($b['sort_order'] ?? 0);
        });
        $cache = $levels;
        return $levels;
    }

    /**
     * 单个等级的标签。
     */
    public static function get_level_label($key)
    {
        $levels = self::get_levels();
        return isset($levels[$key]) ? $levels[$key]['label'] : $key;
    }

    /**
     * 渲染等级徽章 HTML（背景/前景色取自后台自定义 color；无 color 时回退到月光默认）。
     */
    public static function get_level_badge($key)
    {
        $levels = self::get_levels();
        $label  = isset($levels[$key]['label']) ? $levels[$key]['label'] : $key;
        $color  = isset($levels[$key]['color']) ? $levels[$key]['color'] : '';
        $style  = '';
        if ($color && preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
            // 自定义色：浅色背景（hex 取 0.18 alpha），深色前景（直接用 hex）
            $style = ' style="background:' . esc_attr($color) . '1a;color:' . esc_attr($color) . ';border:1px solid ' . esc_attr($color) . '33;"';
        }
        return '<span class="at8-mlucp-level-badge at8-mlucp-level-' . esc_attr($key) . '"' . $style . '>' . esc_html($label) . '</span>';
    }

    /**
     * 当前用户等级 key；未登录或未设置视为 free（free 关闭时为无等级空字符串）。
     */
    public static function get_user_level($user_id = 0)
    {
        $default = self::free_enabled() ? 'free' : '';
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id) {
            return $default;
        }
        $level = get_user_meta($user_id, 'at8_mlucp_membership_level', true);
        if (!$level || !isset(self::get_levels()[$level])) {
            $level = $default;
        }
        // 到期检查：付费等级过期则降级（free 开启→降回 free；free 关闭→清空等级）
        if ($level !== $default && self::is_user_expired($user_id)) {
            if ('' === $default) {
                delete_user_meta($user_id, 'at8_mlucp_membership_level');
                delete_user_meta($user_id, 'at8_mlucp_membership_expires');
            } else {
                self::set_user_level($user_id, $default, 0);
            }
            $level = $default;
        }
        return $level;
    }

    /**
     * 当前用户是否已过期。
     */
    public static function is_user_expired($user_id = 0)
    {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id) {
            return true;
        }
        $expires = (int) get_user_meta($user_id, 'at8_mlucp_membership_expires', true);
        // 未设置到期时间 = 永不过期（用于免费等级或管理员手工设为永久）
        if (!$expires) {
            return false;
        }
        return $expires < time();
    }

    /**
     * 设置用户等级与到期时间。$expires=0 表示永不过期。
     *
     * @param int $user_id
     * @param string $level
     * @param int $expires Unix timestamp
     */
    public static function set_user_level($user_id, $level, $expires = 0)
    {
        $user_id = (int) $user_id;
        // '' 表示清空等级（普通会员关闭时，未付费用户无等级）
        if ('' !== $level && !isset(self::get_levels()[$level])) {
            return false;
        }
        if ('' === $level) {
            delete_user_meta($user_id, 'at8_mlucp_membership_level');
            delete_user_meta($user_id, 'at8_mlucp_membership_expires');
            do_action('at8_mlucp_membership_changed', $user_id, '', 0);
            return true;
        }
        update_user_meta($user_id, 'at8_mlucp_membership_level', $level);
        update_user_meta($user_id, 'at8_mlucp_membership_expires', (int) $expires);
        do_action('at8_mlucp_membership_changed', $user_id, $level, (int) $expires);
        return true;
    }

    /**
     * 授予等級（只升不降）。有效期来自后台定义：
     * - validity = 0：永久（expires=0）。
     * - validity > 0：在「現有有效到期日」基礎上 +N 天；若當前無效則從現在起算。
     *
     * @param int    $user_id
     * @param string $level
     * @return bool
     */
    public function grant_level($user_id, $level)
    {
        $user_id = (int) $user_id;
        $levels  = self::get_levels();
        if (!isset($levels[$level])) {
            return false;
        }
        $current = self::get_user_level($user_id);
        // 已是更高或同等等級則不變更（月費不會覆蓋高級）
        if (self::get_level_sort_order($current) >= self::get_level_sort_order($level)) {
            return false;
        }
        $validity = (int) ($levels[$level]['validity'] ?? 0);
        if ($validity <= 0) {
            // 永久等級（如 premium）
            self::set_user_level($user_id, $level, 0);
            return true;
        }
        $expires = (int) get_user_meta($user_id, 'at8_mlucp_membership_expires', true);
        $base    = ($expires && $expires > time()) ? $expires : time();
        $new_expires = (int) strtotime('+' . $validity . ' days', $base);
        self::set_user_level($user_id, $level, $new_expires);
        return true;
    }

    /**
     * 等级排序权重，用于比较"是否 >= 某等级"。
     */
    public static function get_level_sort_order($level)
    {
        $levels = self::get_levels();
        return isset($levels[$level]) ? (int) $levels[$level]['sort_order'] : 0;
    }

    /**
     * 当前用户是否有权访问指定等级。
     */
    public static function user_can_access($required_level, $user_id = 0)
    {
        // free 内容恒公开：无论普通会员是否启用，标记为 free 的内容对所有人可见
        if ('free' === $required_level) {
            return true;
        }
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id) {
            return false;
        }
        $current = self::get_user_level($user_id);
        return self::get_level_sort_order($current) >= self::get_level_sort_order($required_level);
    }

    /**
     * 当前用户等级到期时间（Unix timestamp），0 表示永久。
     */
    public static function get_user_expires($user_id = 0)
    {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if (!$user_id) {
            return 0;
        }
        return (int) get_user_meta($user_id, 'at8_mlucp_membership_expires', true);
    }

    /**
     * 后台用户编辑页渲染会员等级字段。
     */
    public function render_profile_fields($user)
    {
        // 安全：仅管理员可见/可改会员等级。edit_user 对"用户本人"恒为真，
        // 不能作为守卫，否则任何订阅者都能在个人资料页给自己升到最高等级（提权）。
        if (!current_user_can('manage_options')) {
            return;
        }
        $current_level   = get_user_meta($user->ID, 'at8_mlucp_membership_level', true);
        $current_expires = get_user_meta($user->ID, 'at8_mlucp_membership_expires', true);
        $default_level   = self::free_enabled() ? 'free' : '';
        if (!$current_level || !isset(self::get_levels()[$current_level])) {
            $current_level = $default_level;
        }
        $expires_value = $current_expires ? wp_date('Y-m-d', (int) $current_expires) : '';
        $expires_never = !$current_expires ? ' checked="checked"' : '';
        ?>
        <h2 id="at8-mlucp-membership"><?php echo esc_html__('会员等级', 'at8-moonlight-user-center-pro'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="at8_mlucp_membership_level"><?php echo esc_html__('等级', 'at8-moonlight-user-center-pro'); ?></label></th>
                <td>
                    <select name="at8_mlucp_membership_level" id="at8_mlucp_membership_level">
                        <?php if (!self::free_enabled()) : ?>
                            <option value=""<?php selected($current_level, ''); ?>><?php esc_html_e('（無會員等級）', 'at8-moonlight-user-center-pro'); ?></option>
                        <?php endif; ?>
                        <?php foreach (self::get_levels() as $key => $lv) : ?>
                            <option value="<?php echo esc_attr($key); ?>"<?php selected($current_level, $key); ?>>
                                <?php echo esc_html($lv['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php echo esc_html__('可选等级由后台「会员等级定义」配置。', 'at8-moonlight-user-center-pro'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="at8_mlucp_membership_expires_date"><?php echo esc_html__('到期日', 'at8-moonlight-user-center-pro'); ?></label></th>
                <td>
                    <input type="date" name="at8_mlucp_membership_expires_date" id="at8_mlucp_membership_expires_date" value="<?php echo esc_attr($expires_value); ?>" />
                    <label style="margin-left:1em;">
                        <input type="checkbox" name="at8_mlucp_membership_expires_never" value="1"<?php echo $expires_never; ?> />
                        <?php echo esc_html__('永不过期', 'at8-moonlight-user-center-pro'); ?>
                    </label>
                    <p class="description"><?php echo esc_html__('勾选「永不过期」时忽略日期；不勾选则到期时间为该日 23:59（站点时区）。', 'at8-moonlight-user-center-pro'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * 后台用户编辑页保存会员等级字段。
     */
    public function save_profile_fields($user_id)
    {
        // 安全：同 render，仅管理员可保存等级设置（edit_user 无法防本人提权）。
        if (!current_user_can('manage_options')) {
            return;
        }
        // BUG 修复：字段未提交时不做任何改动（防止其他插件/表单触发 profile 保存钩子时把等级意外重置为 free）。
        if (!isset($_POST['at8_mlucp_membership_level'])) {
            return;
        }
        $level = sanitize_key(wp_unslash($_POST['at8_mlucp_membership_level']));
        $never = !empty($_POST['at8_mlucp_membership_expires_never']);
        $date  = isset($_POST['at8_mlucp_membership_expires_date']) ? sanitize_text_field(wp_unslash($_POST['at8_mlucp_membership_expires_date'])) : '';
        $expires = 0;
        if ($never) {
            $expires = 0;
        } elseif ($date) {
            // BUG 修复：按站点时区解析到期日 23:59（原实现走 UTC，导致 GMT+8 站点提前 8 小时到期）。
            $dt = date_create_from_format('Y-m-d H:i:s', $date . ' 23:59:59', wp_timezone());
            if ($dt instanceof DateTime) {
                $expires = (int) $dt->getTimestamp();
            } else {
                $ts = strtotime($date . ' 23:59:59');
                $expires = $ts ? (int) $ts : 0;
            }
        }
        self::set_user_level($user_id, $level, $expires);
    }
}

/**
 * 全局 helper：当前用户等级。
 */
function at8_mlucp_user_level($user_id = 0)
{
    return AT8_MLUCP_Membership::get_user_level($user_id);
}

/**
 * 全局 helper：当前用户是否有权访问某等级。
 */
function at8_mlucp_user_can_access($required_level, $user_id = 0)
{
    return AT8_MLUCP_Membership::user_can_access($required_level, $user_id);
}

/**
 * 全局 helper：当前用户是否已过期。
 */
function at8_mlucp_user_is_expired($user_id = 0)
{
    return AT8_MLUCP_Membership::is_user_expired($user_id);
}
