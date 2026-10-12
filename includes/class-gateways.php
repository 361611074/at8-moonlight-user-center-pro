<?php
/**
 * Online payment gateways (PayPal / Stripe).
 *
 * The order engine, the offline gateway and the membership grant live in the
 * free plugin. This class only contributes the online gateways:
 *
 * - registers PayPal / Stripe on the free plugin's gateway list,
 * - replaces the default order response with the PayPal or Stripe flow,
 * - owns the AJAX / webhook endpoints that complete orders,
 * - renders the "Online Payment" settings section on the free plugin's
 *   settings screen.
 *
 * @package AT8_Moonlight_User_Center_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Online gateway registration and settings section.
 */
class AT8_MLUCP_Gateways {

	/**
	 * Singleton instance.
	 *
	 * @var AT8_MLUCP_Gateways|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUCP_Gateways
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
		add_filter( 'at8_mluc_payment_gateways', array( $this, 'register_gateways' ) );
		add_filter( 'at8_mluc_payment_order_response', array( $this, 'filter_order_response' ), 10, 4 );

		add_action( 'wp_ajax_at8_mluc_paypal_create', array( 'AT8_MLUCP_PayPal', 'ajax_create' ) );
		add_action( 'wp_ajax_at8_mluc_paypal_capture', array( 'AT8_MLUCP_PayPal', 'ajax_capture' ) );
		add_action( 'wp_ajax_at8_mluc_stripe_checkout', array( 'AT8_MLUCP_Stripe', 'ajax_checkout' ) );
		add_action( 'rest_api_init', array( 'AT8_MLUCP_Stripe', 'register_webhook' ) );
		add_action( 'init', array( 'AT8_MLUCP_Stripe', 'maybe_handle_return' ) );

		// The settings section is contributed to the free plugin's screen.
		add_action( 'at8_mluc_register_settings', array( $this, 'register_settings' ) );
		add_action( 'at8_mluc_settings_extra_fields', array( $this, 'print_settings_fields' ) );
	}

	/**
	 * Add the enabled online gateways to the gateway list.
	 *
	 * @param array $gateways Gateway id => title.
	 * @return array
	 */
	public function register_gateways( $gateways ) {
		// Defence in depth: the bootstrap only boots this class with a valid
		// license, but a gateway must never take payments without one.
		if ( ! AT8_MLUCP_License::is_active() ) {
			return $gateways;
		}

		if ( class_exists( 'AT8_MLUCP_PayPal' ) && AT8_MLUCP_PayPal::enabled() ) {
			$gateways['paypal'] = at8_mlucp_ui_label( 'buy_gateway_paypal', __( 'PayPal (Online Payment)', 'at8-moonlight-user-center-pro' ) );
		}

		if ( class_exists( 'AT8_MLUCP_Stripe' ) && AT8_MLUCP_Stripe::enabled() ) {
			$gateways['stripe'] = at8_mlucp_ui_label( 'buy_gateway_stripe', __( 'Stripe (Credit Card)', 'at8-moonlight-user-center-pro' ) );
		}

		return $gateways;
	}

	/**
	 * Replace the default manual response with the gateway's own flow.
	 *
	 * @param array  $response  Default response payload.
	 * @param int    $order_id  Order ID.
	 * @param string $gateway   Gateway slug.
	 * @param string $level     Level slug.
	 * @return array
	 */
	public function filter_order_response( $response, $order_id, $gateway, $level ) {
		if ( ! AT8_MLUCP_License::is_active() ) {
			return $response;
		}

		if ( 'paypal' === $gateway && class_exists( 'AT8_MLUCP_PayPal' ) ) {
			$response['data']['flow'] = 'paypal';

			return $response;
		}

		if ( 'stripe' === $gateway && class_exists( 'AT8_MLUCP_Stripe' ) ) {
			$session = AT8_MLUCP_Stripe::create_session( $order_id );

			if ( is_wp_error( $session ) ) {
				// Cancel the order so no orphan pending row is left behind.
				update_post_meta( $order_id, '_at8_mluc_pay_status', 'cancelled' );

				return array(
					'success' => false,
					'message' => $session->get_error_message(),
					'data'    => array(),
				);
			}

			update_post_meta( $order_id, '_at8_mluc_pay_txn_ref', $session['id'] );

			$response['message'] = at8_mlucp_ui_label( 'buy_order_stripe', __( 'Order created. Redirecting to Stripe…', 'at8-moonlight-user-center-pro' ) );
			$response['data']    = array(
				'order_id' => (int) $order_id,
				'flow'     => 'stripe',
				'redirect' => $session['url'],
			);

			return $response;
		}

		return $response;
	}

	/**
	 * Register the online payment section on the free settings screen.
	 *
	 * @return void
	 */
	public function register_settings() {
		if ( ! class_exists( 'AT8_MLUC_Settings' ) ) {
			return;
		}

		$page = AT8_MLUC_Settings::PAGE;

		add_settings_section(
			'at8_mlucp_payment_online',
			__( 'Online Payment (PayPal / Stripe)', 'at8-moonlight-user-center-pro' ),
			array( $this, 'section_online_payment' ),
			$page
		);

		add_settings_field(
			'at8_mlucp_payment_online',
			__( 'Gateway credentials', 'at8-moonlight-user-center-pro' ),
			array( $this, 'render_online_payment_field' ),
			$page,
			'at8_mlucp_payment_online'
		);

		add_settings_section(
			'at8_mlucp_orders',
			__( 'Orders', 'at8-moonlight-user-center-pro' ),
			'__return_false',
			$page
		);

		add_settings_field(
			'at8_mlucp_orders',
			__( 'Order management', 'at8-moonlight-user-center-pro' ),
			array( $this, 'render_orders_field' ),
			$page,
			'at8_mlucp_orders'
		);
	}

	/**
	 * Online payment section description.
	 *
	 * @return void
	 */
	public function section_online_payment() {
		if ( ! AT8_MLUCP_License::is_active() ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'These gateways stay dormant until a valid Pro license is active. The bank-transfer gateway of the free plugin keeps working regardless.', 'at8-moonlight-user-center-pro' )
			);

			return;
		}

		echo '<p class="description">' . esc_html__( 'Configured gateways appear next to bank transfer in the purchase flow. The currency code is shared and lives in Offline Payments.', 'at8-moonlight-user-center-pro' ) . '</p>';
	}

	/**
	 * Render the online payment fields.
	 *
	 * @return void
	 */
	public function render_online_payment_field() {
		$pp_enabled = at8_mlucp_get_option( 'paypal_enabled', 0 );
		$pp_mode    = at8_mlucp_get_option( 'paypal_mode', 'sandbox' );
		$pp_cid     = at8_mlucp_get_option( 'paypal_client_id', '' );
		$pp_secret  = at8_mlucp_get_option( 'paypal_secret', '' );
		$st_enabled = at8_mlucp_get_option( 'stripe_enabled', 0 );
		$st_pk      = at8_mlucp_get_option( 'stripe_pk', '' );
		$st_sk      = at8_mlucp_get_option( 'stripe_sk', '' );
		$st_wh      = at8_mlucp_get_option( 'stripe_webhook_secret', '' );
		$webhook    = rest_url( 'at8-mlucp/v1/stripe-webhook' );
		$option     = 'at8_mlucp_options';
		?>
		<p>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[paypal_enabled]" value="1" <?php checked( $pp_enabled, 1 ); ?>>
				<?php esc_html_e( 'Enable PayPal (Smart Buttons, server-side capture)', 'at8-moonlight-user-center-pro' ); ?>
			</label>
		</p>
		<p>
			<label for="at8_mlucp_pp_mode"><?php esc_html_e( 'Mode', 'at8-moonlight-user-center-pro' ); ?></label>
			<select id="at8_mlucp_pp_mode" name="<?php echo esc_attr( $option ); ?>[paypal_mode]">
				<option value="sandbox" <?php selected( $pp_mode, 'sandbox' ); ?>><?php esc_html_e( 'Sandbox', 'at8-moonlight-user-center-pro' ); ?></option>
				<option value="live" <?php selected( $pp_mode, 'live' ); ?>><?php esc_html_e( 'Live', 'at8-moonlight-user-center-pro' ); ?></option>
			</select>
		</p>
		<p>
			<label for="at8_mlucp_pp_cid"><?php esc_html_e( 'Client ID', 'at8-moonlight-user-center-pro' ); ?></label><br>
			<input type="text" id="at8_mlucp_pp_cid" class="large-text code" name="<?php echo esc_attr( $option ); ?>[paypal_client_id]" value="<?php echo esc_attr( $pp_cid ); ?>" autocomplete="off">
		</p>
		<p>
			<label for="at8_mlucp_pp_secret"><?php esc_html_e( 'Secret', 'at8-moonlight-user-center-pro' ); ?></label><br>
			<input type="password" id="at8_mlucp_pp_secret" class="large-text code" name="<?php echo esc_attr( $option ); ?>[paypal_secret]" value="<?php echo esc_attr( $pp_secret ); ?>" autocomplete="new-password">
		</p>
		<hr>
		<p>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[stripe_enabled]" value="1" <?php checked( $st_enabled, 1 ); ?>>
				<?php esc_html_e( 'Enable Stripe (hosted Checkout, cards / Apple Pay / Google Pay)', 'at8-moonlight-user-center-pro' ); ?>
			</label>
		</p>
		<p>
			<label for="at8_mlucp_st_pk"><?php esc_html_e( 'Publishable Key', 'at8-moonlight-user-center-pro' ); ?></label><br>
			<input type="text" id="at8_mlucp_st_pk" class="large-text code" name="<?php echo esc_attr( $option ); ?>[stripe_pk]" value="<?php echo esc_attr( $st_pk ); ?>" placeholder="pk_live_... / pk_test_..." autocomplete="off">
		</p>
		<p>
			<label for="at8_mlucp_st_sk"><?php esc_html_e( 'Secret Key', 'at8-moonlight-user-center-pro' ); ?></label><br>
			<input type="password" id="at8_mlucp_st_sk" class="large-text code" name="<?php echo esc_attr( $option ); ?>[stripe_sk]" value="<?php echo esc_attr( $st_sk ); ?>" placeholder="sk_live_... / sk_test_..." autocomplete="new-password">
		</p>
		<p>
			<label for="at8_mlucp_st_wh"><?php esc_html_e( 'Webhook signing secret (optional, fallback fulfilment)', 'at8-moonlight-user-center-pro' ); ?></label><br>
			<input type="password" id="at8_mlucp_st_wh" class="large-text code" name="<?php echo esc_attr( $option ); ?>[stripe_webhook_secret]" value="<?php echo esc_attr( $st_wh ); ?>" placeholder="whsec_..." autocomplete="new-password">
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
	 * Render the orders section: a shortcut to the order list.
	 *
	 * @return void
	 */
	public function render_orders_field() {
		$orders_url = admin_url( 'edit.php?post_type=at8_mluc_order' );
		$hours      = (int) at8_mluc_get_option( 'order_auto_close_hours', 72 );
		?>
		<p>
			<a href="<?php echo esc_url( $orders_url ); ?>" class="button"><?php esc_html_e( 'Open the order list →', 'at8-moonlight-user-center-pro' ); ?></a>
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: %d: number of hours after which unpaid orders are cancelled. */
				esc_html__( 'Confirming payment on an order grants the membership level (or unlocks the paid content) automatically. Unpaid orders are cancelled after %d hours; the timeout lives in Offline Payments.', 'at8-moonlight-user-center-pro' ),
				(int) $hours
			);
			?>
		</p>
		<?php
	}

	/**
	 * Print the Pro option nonce fields inside the free settings form.
	 *
	 * @return void
	 */
	public function print_settings_fields() {
		settings_fields( AT8_MLUCP_Settings::OPTION_GROUP );
	}
}
