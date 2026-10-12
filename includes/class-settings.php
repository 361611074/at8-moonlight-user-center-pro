<?php
/**
 * Pro settings screen.
 *
 * The screen is attached under the free plugin's settings menu
 * (`at8-mluc-settings`), so the whole product keeps one entry point in the
 * WordPress admin. Every field writes into the Pro option set
 * (`at8_mlucp_options`), which is completely independent from the free
 * plugin's options.
 *
 * @package AT8_Moonlight_User_Center_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pro settings screen.
 */
class AT8_MLUCP_Settings {

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	const PAGE = 'at8-mlucp-settings';

	/**
	 * Option group name.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'at8_mlucp_settings';

	/**
	 * Singleton instance.
	 *
	 * @var AT8_MLUCP_Settings|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUCP_Settings
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AT8_MLUCP_PLUGIN_FILE ), array( $this, 'add_action_link' ) );
	}

	/**
	 * Register the settings screen under the free plugin's menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'at8-mluc-settings',
			__( 'User Center Pro Settings', 'at8-moonlight-user-center-pro' ),
			__( 'Pro Settings', 'at8-moonlight-user-center-pro' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Add a settings shortcut on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function add_action_link( $links ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}

		$url = add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Pro Settings', 'at8-moonlight-user-center-pro' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Register the option and its fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			'at8_mlucp_options',
			array(
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => AT8_MLUCP_Activator::defaults(),
			)
		);

		// Registered in the same group so options.php persists it next to the
		// main option blob, but it lives in its own option key: the license
		// class reads it on every check, independent of the settings blob.
		register_setting(
			self::OPTION_GROUP,
			AT8_MLUCP_License::SERVER_OPTION_KEY,
			array(
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);

		add_settings_section(
			'at8_mlucp_membership',
			__( 'Membership Levels', 'at8-moonlight-user-center-pro' ),
			'__return_false',
			self::PAGE
		);
		add_settings_field(
			'at8_mlucp_membership_levels',
			__( 'Level definitions', 'at8-moonlight-user-center-pro' ),
			array( $this, 'render_membership_field' ),
			self::PAGE,
			'at8_mlucp_membership'
		);

		add_settings_section(
			'at8_mlucp_payment',
			__( 'Offline Payment', 'at8-moonlight-user-center-pro' ),
			'__return_false',
			self::PAGE
		);
		add_settings_field(
			'at8_mlucp_payment_manual',
			__( 'Bank transfer', 'at8-moonlight-user-center-pro' ),
			array( $this, 'render_payment_field' ),
			self::PAGE,
			'at8_mlucp_payment'
		);

		add_settings_section(
			'at8_mlucp_payment_online',
			__( 'Online Payment (PayPal / Stripe)', 'at8-moonlight-user-center-pro' ),
			'__return_false',
			self::PAGE
		);
		add_settings_field(
			'at8_mlucp_payment_online',
			__( 'Gateway credentials', 'at8-moonlight-user-center-pro' ),
			array( $this, 'render_online_payment_field' ),
			self::PAGE,
			'at8_mlucp_payment_online'
		);

		add_settings_section(
			'at8_mlucp_orders',
			__( 'Orders', 'at8-moonlight-user-center-pro' ),
			'__return_false',
			self::PAGE
		);
		add_settings_field(
			'at8_mlucp_order_autoclose',
			__( 'Auto-close timeout', 'at8-moonlight-user-center-pro' ),
			array( $this, 'render_orders_field' ),
			self::PAGE,
			'at8_mlucp_orders'
		);

		add_settings_section(
			'at8_mlucp_oauth',
			__( 'Social Login', 'at8-moonlight-user-center-pro' ),
			'__return_false',
			self::PAGE
		);
		add_settings_field(
			'at8_mlucp_oauth',
			__( 'OAuth credentials', 'at8-moonlight-user-center-pro' ),
			array( $this, 'render_oauth_field' ),
			self::PAGE,
			'at8_mlucp_oauth'
		);

		add_settings_section(
			'at8_mlucp_license_server',
			__( 'License Server (optional)', 'at8-moonlight-user-center-pro' ),
			'__return_false',
			self::PAGE
		);
		add_settings_field(
			'at8_mlucp_license_server',
			__( 'Remote validation', 'at8-moonlight-user-center-pro' ),
			array( $this, 'render_server_field' ),
			self::PAGE,
			'at8_mlucp_license_server'
		);
	}

	/**
	 * Enqueue the admin script for the level editor.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}

		wp_enqueue_script(
			'at8-mlucp-admin',
			AT8_MLUCP_PLUGIN_URL . 'assets/js/at8-mlucp-admin.js',
			array(),
			AT8_MLUCP_VERSION,
			true
		);
	}

	/**
	 * Render the membership level editor.
	 *
	 * @return void
	 */
	public function render_membership_field() {
		if ( ! class_exists( 'AT8_MLUCP_Membership' ) ) {
			echo '<p class="description">' . esc_html__( 'The membership module is not loaded.', 'at8-moonlight-user-center-pro' ) . '</p>';
			return;
		}

		$levels  = AT8_MLUCP_Membership::get_levels();
		$free_on = AT8_MLUCP_Membership::free_enabled();

		$default_colors = array(
			'free'    => '#95a5a6',
			'monthly' => '#3498db',
			'gold'    => '#f1c40f',
			'premium' => '#9b59b6',
			'diamond' => '#1abc9c',
		);
		?>
		<p>
			<label>
				<input type="checkbox" name="at8_mlucp_options[free_level_enabled]" value="1" <?php checked( $free_on ); ?>>
				<strong><?php esc_html_e( 'Enable the free base level', 'at8-moonlight-user-center-pro' ); ?></strong>
			</label>
		</p>
		<p class="description">
			<?php esc_html_e( 'When off, only paid levels remain: visitors without a membership hold no level at all. Content marked "free" stays visible to everyone either way.', 'at8-moonlight-user-center-pro' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Add, edit or remove levels here. Deleting a level downgrades everyone who held it; deleting the free level is the same as switching the base level off above. Price and validity drive the purchase flow.', 'at8-moonlight-user-center-pro' ); ?>
		</p>
		<table class="at8-mlucp-levels-table widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Key', 'at8-moonlight-user-center-pro' ); ?></th>
					<th><?php esc_html_e( 'Label', 'at8-moonlight-user-center-pro' ); ?></th>
					<th><?php esc_html_e( 'Price', 'at8-moonlight-user-center-pro' ); ?></th>
					<th><?php esc_html_e( 'Validity (days)', 'at8-moonlight-user-center-pro' ); ?></th>
					<th><?php esc_html_e( 'Colour', 'at8-moonlight-user-center-pro' ); ?></th>
					<th><?php esc_html_e( 'Sort', 'at8-moonlight-user-center-pro' ); ?></th>
					<th><?php esc_html_e( 'Action', 'at8-moonlight-user-center-pro' ); ?></th>
				</tr>
			</thead>
			<tbody id="at8-mlucp-levels-tbody">
				<?php foreach ( $levels as $key => $level ) : ?>
					<?php
					$is_free  = ( 'free' === $key );
					$label    = isset( $level['label'] ) ? $level['label'] : $key;
					$price    = isset( $level['price'] ) ? $level['price'] : 0;
					$validity = isset( $level['validity'] ) ? (int) $level['validity'] : 0;
					$color    = ! empty( $level['color'] ) ? $level['color'] : ( isset( $default_colors[ $key ] ) ? $default_colors[ $key ] : '#2f6fed' );
					$sort     = isset( $level['sort_order'] ) ? (int) $level['sort_order'] : 50;
					$name     = 'at8_mlucp_options[membership_levels][' . $key . ']';
					?>
					<tr>
						<td>
							<code><?php echo esc_html( $key ); ?></code>
							<?php if ( $is_free ) : ?><span class="description"><?php esc_html_e( 'base', 'at8-moonlight-user-center-pro' ); ?></span><?php endif; ?>
						</td>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $label ); ?>"></td>
						<td>
							<?php if ( $is_free ) : ?>
								—
							<?php else : ?>
								<input type="number" step="0.01" min="0" class="small-text" name="<?php echo esc_attr( $name ); ?>[price]" value="<?php echo esc_attr( $price ); ?>">
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $is_free ) : ?>
								<?php esc_html_e( 'forever', 'at8-moonlight-user-center-pro' ); ?>
							<?php else : ?>
								<input type="number" min="0" class="small-text" name="<?php echo esc_attr( $name ); ?>[validity]" value="<?php echo esc_attr( $validity ); ?>">
							<?php endif; ?>
						</td>
						<td><input type="color" name="<?php echo esc_attr( $name ); ?>[color]" value="<?php echo esc_attr( $color ); ?>"></td>
						<td><input type="number" min="0" class="small-text" name="<?php echo esc_attr( $name ); ?>[sort_order]" value="<?php echo esc_attr( $sort ); ?>"></td>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete]" value="1"> <?php esc_html_e( 'Delete', 'at8-moonlight-user-center-pro' ); ?></label>
							<?php if ( $is_free ) : ?><span class="description"><?php esc_html_e( 'deleting the base level disables it', 'at8-moonlight-user-center-pro' ); ?></span><?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button" id="at8-mlucp-add-level"><?php esc_html_e( '+ Add level', 'at8-moonlight-user-center-pro' ); ?></button></p>

		<template id="at8-mlucp-level-row-tpl">
			<tr>
				<td><input type="text" class="regular-text" data-name="at8_mlucp_options[membership_levels][__NEWKEY__][key]" placeholder="level_key"></td>
				<td><input type="text" class="regular-text" data-name="at8_mlucp_options[membership_levels][__NEWKEY__][label]" placeholder="<?php esc_attr_e( 'Label', 'at8-moonlight-user-center-pro' ); ?>"></td>
				<td><input type="number" step="0.01" min="0" class="small-text" data-name="at8_mlucp_options[membership_levels][__NEWKEY__][price]" value="0"></td>
				<td><input type="number" min="0" class="small-text" data-name="at8_mlucp_options[membership_levels][__NEWKEY__][validity]" value="30"></td>
				<td><input type="color" data-name="at8_mlucp_options[membership_levels][__NEWKEY__][color]" value="#2f6fed"></td>
				<td><input type="number" min="0" class="small-text" data-name="at8_mlucp_options[membership_levels][__NEWKEY__][sort_order]" value="50"></td>
				<td><button type="button" class="at8-mlucp-del-level"><?php esc_html_e( 'Delete', 'at8-moonlight-user-center-pro' ); ?></button></td>
			</tr>
		</template>
		<?php
	}

	/**
	 * Render the offline payment fields.
	 *
	 * @return void
	 */
	public function render_payment_field() {
		$symbol       = at8_mlucp_get_option( 'pay_currency_symbol', '$' );
		$instructions = at8_mlucp_get_option( 'pay_manual_instructions', '' );
		$manual_on    = at8_mlucp_get_option( 'manual_enabled', 1 );
		?>
		<p>
			<label>
				<input type="checkbox" name="at8_mlucp_options[manual_enabled]" value="1" <?php checked( $manual_on, 1 ); ?>>
				<?php esc_html_e( 'Enable bank transfer (the admin confirms payment manually)', 'at8-moonlight-user-center-pro' ); ?>
			</label>
		</p>
		<p>
			<label for="at8_mlucp_pay_symbol"><?php esc_html_e( 'Currency symbol', 'at8-moonlight-user-center-pro' ); ?></label><br>
			<input type="text" id="at8_mlucp_pay_symbol" class="small-text" name="at8_mlucp_options[pay_currency_symbol]" value="<?php echo esc_attr( $symbol ); ?>" maxlength="8">
		</p>
		<p>
			<label for="at8_mlucp_pay_instructions"><?php esc_html_e( 'Transfer instructions', 'at8-moonlight-user-center-pro' ); ?></label><br>
			<textarea id="at8_mlucp_pay_instructions" class="large-text" rows="4" name="at8_mlucp_options[pay_manual_instructions]"><?php echo esc_textarea( $instructions ); ?></textarea>
			<span class="description"><?php esc_html_e( 'Shown to the buyer after an offline order is created. Leave empty for the built-in default.', 'at8-moonlight-user-center-pro' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Render the online payment fields.
	 *
	 * @return void
	 */
	public function render_online_payment_field() {
		$code       = at8_mlucp_get_option( 'pay_currency_code', 'USD' );
		$pp_enabled = at8_mlucp_get_option( 'paypal_enabled', 0 );
		$pp_mode    = at8_mlucp_get_option( 'paypal_mode', 'sandbox' );
		$pp_cid     = at8_mlucp_get_option( 'paypal_client_id', '' );
		$pp_secret  = at8_mlucp_get_option( 'paypal_secret', '' );
		$st_enabled = at8_mlucp_get_option( 'stripe_enabled', 0 );
		$st_pk      = at8_mlucp_get_option( 'stripe_pk', '' );
		$st_sk      = at8_mlucp_get_option( 'stripe_sk', '' );
		$st_wh      = at8_mlucp_get_option( 'stripe_webhook_secret', '' );
		$webhook    = rest_url( 'at8-mlucp/v1/stripe-webhook' );
		?>
		<p>
			<label for="at8_mlucp_currency_code"><strong><?php esc_html_e( 'Currency code (ISO 4217, shared by both gateways)', 'at8-moonlight-user-center-pro' ); ?></strong></label><br>
			<input type="text" id="at8_mlucp_currency_code" class="small-text" name="at8_mlucp_options[pay_currency_code]" value="<?php echo esc_attr( $code ); ?>" placeholder="USD" maxlength="3">
			<span class="description"><?php esc_html_e( 'For example USD / HKD / EUR / JPY. Must match the currency of the level prices.', 'at8-moonlight-user-center-pro' ); ?></span>
		</p>
		<hr>
		<p>
			<label>
				<input type="checkbox" name="at8_mlucp_options[paypal_enabled]" value="1" <?php checked( $pp_enabled, 1 ); ?>>
				<?php esc_html_e( 'Enable PayPal (Smart Buttons, server-side capture)', 'at8-moonlight-user-center-pro' ); ?>
			</label>
		</p>
		<p>
			<label for="at8_mlucp_pp_mode"><?php esc_html_e( 'Mode', 'at8-moonlight-user-center-pro' ); ?></label>
			<select id="at8_mlucp_pp_mode" name="at8_mlucp_options[paypal_mode]">
				<option value="sandbox" <?php selected( $pp_mode, 'sandbox' ); ?>><?php esc_html_e( 'Sandbox', 'at8-moonlight-user-center-pro' ); ?></option>
				<option value="live" <?php selected( $pp_mode, 'live' ); ?>><?php esc_html_e( 'Live', 'at8-moonlight-user-center-pro' ); ?></option>
			</select>
		</p>
		<p>
			<label for="at8_mlucp_pp_cid"><?php esc_html_e( 'Client ID', 'at8-moonlight-user-center-pro' ); ?></label><br>
			<input type="text" id="at8_mlucp_pp_cid" class="large-text code" name="at8_mlucp_options[paypal_client_id]" value="<?php echo esc_attr( $pp_cid ); ?>" autocomplete="off">
		</p>
		<p>
			<label for="at8_mlucp_pp_secret"><?php esc_html_e( 'Secret', 'at8-moonlight-user-center-pro' ); ?></label><br>
			<input type="password" id="at8_mlucp_pp_secret" class="large-text code" name="at8_mlucp_options[paypal_secret]" value="<?php echo esc_attr( $pp_secret ); ?>" autocomplete="new-password">
		</p>
		<hr>
		<p>
			<label>
				<input type="checkbox" name="at8_mlucp_options[stripe_enabled]" value="1" <?php checked( $st_enabled, 1 ); ?>>
				<?php esc_html_e( 'Enable Stripe (hosted Checkout, cards / Apple Pay / Google Pay)', 'at8-moonlight-user-center-pro' ); ?>
			</label>
		</p>
		<p>
			<label for="at8_mlucp_st_pk"><?php esc_html_e( 'Publishable Key', 'at8-moonlight-user-center-pro' ); ?></label><br>
			<input type="text" id="at8_mlucp_st_pk" class="large-text code" name="at8_mlucp_options[stripe_pk]" value="<?php echo esc_attr( $st_pk ); ?>" placeholder="pk_live_... / pk_test_..." autocomplete="off">
		</p>
		<p>
			<label for="at8_mlucp_st_sk"><?php esc_html_e( 'Secret Key', 'at8-moonlight-user-center-pro' ); ?></label><br>
			<input type="password" id="at8_mlucp_st_sk" class="large-text code" name="at8_mlucp_options[stripe_sk]" value="<?php echo esc_attr( $st_sk ); ?>" placeholder="sk_live_... / sk_test_..." autocomplete="new-password">
		</p>
		<p>
			<label for="at8_mlucp_st_wh"><?php esc_html_e( 'Webhook signing secret (optional, fallback fulfilment)', 'at8-moonlight-user-center-pro' ); ?></label><br>
			<input type="password" id="at8_mlucp_st_wh" class="large-text code" name="at8_mlucp_options[stripe_webhook_secret]" value="<?php echo esc_attr( $st_wh ); ?>" placeholder="whsec_..." autocomplete="new-password">
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: %s: webhook endpoint URL */
				esc_html__( 'For webhook fallback, add the endpoint %s in the Stripe dashboard, subscribe to checkout.session.completed and paste the signing secret above.', 'at8-moonlight-user-center-pro' ),
				'<code>' . esc_html( $webhook ) . '</code>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render the order auto-close field.
	 *
	 * @return void
	 */
	public function render_orders_field() {
		$hours = (int) at8_mlucp_get_option( 'order_auto_close_hours', 72 );
		?>
		<p>
			<label for="at8_mlucp_autoclose"><?php esc_html_e( 'Close pending orders older than (hours)', 'at8-moonlight-user-center-pro' ); ?></label><br>
			<input type="number" id="at8_mlucp_autoclose" class="small-text" min="0" max="8760" name="at8_mlucp_options[order_auto_close_hours]" value="<?php echo esc_attr( $hours ); ?>">
			<span class="description"><?php esc_html_e( '0 disables auto-closing. Never set this below 24 hours: an in-flight Stripe Checkout session can last that long.', 'at8-moonlight-user-center-pro' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Render the OAuth credentials table.
	 *
	 * @return void
	 */
	public function render_oauth_field() {
		if ( ! class_exists( 'AT8_MLUCP_OAuth' ) ) {
			echo '<p class="description">' . esc_html__( 'The social login module is not loaded.', 'at8-moonlight-user-center-pro' ) . '</p>';
			return;
		}

		$providers = AT8_MLUCP_OAuth::providers();
		$saved     = at8_mlucp_get_option( 'oauth', array() );
		$saved     = is_array( $saved ) ? $saved : array();
		?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: example callback URL */
				esc_html__( 'Register the callback URL (example: %s) on the provider platform. Only providers with both a client ID and a secret appear as front-end login buttons.', 'at8-moonlight-user-center-pro' ),
				'<code>' . esc_html( home_url( '/?at8_mlucp_oauth=wechat' ) ) . '</code>'
			);
			?>
		</p>
		<table class="at8-mlucp-oauth-table widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Provider', 'at8-moonlight-user-center-pro' ); ?></th>
					<th><?php esc_html_e( 'Client ID / App ID', 'at8-moonlight-user-center-pro' ); ?></th>
					<th><?php esc_html_e( 'Client Secret', 'at8-moonlight-user-center-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $providers as $id => $provider ) : ?>
					<?php
					$credentials = isset( $saved[ $id ] ) && is_array( $saved[ $id ] ) ? $saved[ $id ] : array();
					$client_id   = isset( $credentials['client_id'] ) ? $credentials['client_id'] : '';
					$secret      = isset( $credentials['client_secret'] ) ? $credentials['client_secret'] : '';
					?>
					<tr>
						<td><strong><?php echo esc_html( $provider['label'] ); ?></strong></td>
						<td><input type="text" class="regular-text" name="at8_mlucp_options[oauth][<?php echo esc_attr( $id ); ?>][client_id]" value="<?php echo esc_attr( $client_id ); ?>"></td>
						<td><input type="password" class="regular-text" name="at8_mlucp_options[oauth][<?php echo esc_attr( $id ); ?>][client_secret]" value="<?php echo esc_attr( $secret ); ?>"></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the license server field.
	 *
	 * @return void
	 */
	public function render_server_field() {
		$server = AT8_MLUCP_License::get_server();
		?>
		<p>
			<label for="at8_mlucp_license_server"><?php esc_html_e( 'License server URL', 'at8-moonlight-user-center-pro' ); ?></label><br>
			<input type="url" id="at8_mlucp_license_server" class="large-text code" name="at8_mlucp_license_server" value="<?php echo esc_attr( $server ); ?>" placeholder="https://license.example.com/" autocomplete="off">
		</p>
		<p class="description">
			<?php esc_html_e( 'Leave empty for offline mode: signed keys are validated locally and no request leaves this site. When set, activation, heartbeat and updates go to that server; an unreachable server grants a 14-day grace period instead of disabling Pro.', 'at8-moonlight-user-center-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! AT8_MLUCP_License::is_active() ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Pro features are inactive because no valid license is active.', 'at8-moonlight-user-center-pro' ),
				esc_url( admin_url( 'admin.php?page=at8-mlucp-license' ) ),
				esc_html__( 'Manage license', 'at8-moonlight-user-center-pro' )
			);
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'User Center Pro Settings', 'at8-moonlight-user-center-pro' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<?php do_settings_sections( self::PAGE ); ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Sanitize the submitted options.
	 *
	 * The option is rebuilt key by key from a whitelist, so no unexpected key
	 * can ever be written into the database through this screen.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array Sanitized options.
	 */
	public function sanitize_options( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$options = get_option( 'at8_mlucp_options', array() );
		$options = is_array( $options ) ? $options : array();

		$options['free_level_enabled'] = ! empty( $input['free_level_enabled'] ) ? 1 : 0;
		$options['manual_enabled']     = ! empty( $input['manual_enabled'] ) ? 1 : 0;

		$options['pay_currency_symbol']     = isset( $input['pay_currency_symbol'] ) ? sanitize_text_field( wp_unslash( $input['pay_currency_symbol'] ) ) : '';
		$options['pay_manual_instructions'] = isset( $input['pay_manual_instructions'] ) ? sanitize_textarea_field( wp_unslash( $input['pay_manual_instructions'] ) ) : '';
		$options['order_auto_close_hours']  = isset( $input['order_auto_close_hours'] ) ? max( 0, min( 8760, (int) $input['order_auto_close_hours'] ) ) : 72;

		$code = strtoupper( sanitize_text_field( wp_unslash( $input['pay_currency_code'] ?? '' ) ) );
		$options['pay_currency_code'] = preg_match( '/^[A-Z]{3}$/', $code ) ? $code : 'USD';

		$options['paypal_enabled']   = ! empty( $input['paypal_enabled'] ) ? 1 : 0;
		$paypal_mode                 = sanitize_key( wp_unslash( $input['paypal_mode'] ?? 'sandbox' ) );
		$options['paypal_mode']      = in_array( $paypal_mode, array( 'sandbox', 'live' ), true ) ? $paypal_mode : 'sandbox';
		$options['paypal_client_id'] = isset( $input['paypal_client_id'] ) ? sanitize_text_field( wp_unslash( $input['paypal_client_id'] ) ) : '';
		$options['paypal_secret']    = isset( $input['paypal_secret'] ) ? sanitize_text_field( wp_unslash( $input['paypal_secret'] ) ) : '';

		$options['stripe_enabled']        = ! empty( $input['stripe_enabled'] ) ? 1 : 0;
		$options['stripe_pk']             = isset( $input['stripe_pk'] ) ? sanitize_text_field( wp_unslash( $input['stripe_pk'] ) ) : '';
		$options['stripe_sk']             = isset( $input['stripe_sk'] ) ? sanitize_text_field( wp_unslash( $input['stripe_sk'] ) ) : '';
		$options['stripe_webhook_secret'] = isset( $input['stripe_webhook_secret'] ) ? sanitize_text_field( wp_unslash( $input['stripe_webhook_secret'] ) ) : '';

		$options['oauth'] = array();
		if ( isset( $input['oauth'] ) && is_array( $input['oauth'] ) ) {
			foreach ( $input['oauth'] as $provider => $credentials ) {
				$provider = sanitize_key( $provider );

				if ( '' === $provider || ! is_array( $credentials ) ) {
					continue;
				}

				$client_id = isset( $credentials['client_id'] ) ? sanitize_text_field( wp_unslash( $credentials['client_id'] ) ) : '';
				$secret    = isset( $credentials['client_secret'] ) ? sanitize_text_field( wp_unslash( $credentials['client_secret'] ) ) : '';

				if ( '' !== $client_id || '' !== $secret ) {
					$options['oauth'][ $provider ] = array(
						'client_id'     => $client_id,
						'client_secret' => $secret,
					);
				}
			}
		}

		$options['membership_levels'] = $this->sanitize_levels( isset( $input['membership_levels'] ) ? $input['membership_levels'] : array() );

		return $options;
	}

	/**
	 * Sanitize the membership level definitions.
	 *
	 * @param mixed $input Raw levels value.
	 * @return array<string, array<string, mixed>>
	 */
	protected function sanitize_levels( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$clean = array();

		foreach ( $input as $raw_key => $level ) {
			if ( ! is_array( $level ) ) {
				continue;
			}

			$is_new = ( 0 === strpos( (string) $raw_key, '__new__' ) );

			// Checked "Delete" boxes remove existing levels outright; new rows
			// are simply never added.
			if ( ! $is_new && ! empty( $level['delete'] ) ) {
				continue;
			}

			if ( $is_new ) {
				$key = isset( $level['key'] ) ? sanitize_key( wp_unslash( $level['key'] ) ) : '';

				if ( '' === $key ) {
					$key = isset( $level['label'] ) ? sanitize_key( wp_unslash( $level['label'] ) ) : '';
				}

				if ( '' === $key || 'free' === $key || isset( $clean[ $key ] ) ) {
					continue;
				}
			} else {
				$key = sanitize_key( (string) $raw_key );

				if ( '' === $key ) {
					continue;
				}
			}

			$validity = isset( $level['validity'] ) ? max( 0, (int) $level['validity'] ) : 0;
			$color    = isset( $level['color'] ) ? sanitize_text_field( wp_unslash( $level['color'] ) ) : '';

			if ( ! preg_match( '/^#[0-9a-fA-F]{3,8}$/', $color ) ) {
				$color = '#2f6fed';
			}

			$clean[ $key ] = array(
				'label'      => isset( $level['label'] ) && '' !== sanitize_text_field( wp_unslash( $level['label'] ) ) ? sanitize_text_field( wp_unslash( $level['label'] ) ) : $key,
				'color'      => $color,
				'price'      => 'free' === $key ? 0 : max( 0, (float) ( $level['price'] ?? 0 ) ),
				'validity'   => 'free' === $key ? 0 : $validity,
				'sort_order' => isset( $level['sort_order'] ) ? max( 0, (int) $level['sort_order'] ) : 50,
			);
		}

		return $clean;
	}
}
