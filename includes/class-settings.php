<?php
/**
 * Pro option registration and sanitisation.
 *
 * There is no separate Pro settings screen: since 1.3.0 the online payment
 * section and the orders shortcut are contributed to the free plugin's
 * settings page (see AT8_MLUCP_Gateways), so the whole product family keeps
 * one settings screen under the "User Center" menu. This class owns the Pro
 * option blob (`at8_mlucp_options`) and its sanitisation.
 *
 * @package AT8_Moonlight_User_Center_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pro option registration and sanitisation.
 */
class AT8_MLUCP_Settings {

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
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		/*
		 * `admin_page_access_denied` (fired from wp-admin/includes/menu.php)
		 * is the only hook that runs before WordPress renders its 403 for an
		 * unregistered admin page; admin_init is too late, because core
		 * decides already while building the menu.
		 */
		add_action( 'admin_page_access_denied', array( $this, 'maybe_redirect_legacy_page' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AT8_MLUCP_PLUGIN_FILE ), array( $this, 'add_action_link' ) );
	}

	/**
	 * Redirect the retired 1.2.x settings slug to the shared screen.
	 *
	 * Up to 1.2.0 Pro owned `at8-mlucp-settings`. That page moved into the
	 * free plugin's settings screen in 1.3.0, so bookmarks, e-mailed links
	 * and the action link cached by other code would otherwise land on a
	 * WordPress 403 "you are not allowed to access this page" screen.
	 *
	 * @return void
	 */
	public function maybe_redirect_legacy_page() {
		global $pagenow, $plugin_page;

		if ( 'admin.php' !== $pagenow || ! isset( $plugin_page ) ) {
			return;
		}

		if ( 'at8-mlucp-settings' !== sanitize_key( (string) $plugin_page ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! class_exists( 'AT8_MLUC_Settings' ) ) {
			return;
		}

		wp_safe_redirect( add_query_arg( 'page', AT8_MLUC_Settings::PAGE, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Add a settings shortcut on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function add_action_link( $links ) {
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'AT8_MLUC_Settings' ) ) {
			return $links;
		}

		$url = add_query_arg( 'page', AT8_MLUC_Settings::PAGE, admin_url( 'admin.php' ) );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'at8-moonlight-user-center-pro' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Register the Pro option.
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
	}

	/**
	 * Sanitize the submitted options.
	 *
	 * Only the online gateway keys are managed here; every other key of the
	 * Pro option blob (videos, materials, paywall) is preserved as stored.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array Sanitized options.
	 */
	public function sanitize_options( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$options = get_option( 'at8_mlucp_options', array() );
		$options = is_array( $options ) ? $options : array();

		$options['paypal_enabled']   = ! empty( $input['paypal_enabled'] ) ? 1 : 0;
		$paypal_mode                 = sanitize_key( wp_unslash( $input['paypal_mode'] ?? 'sandbox' ) );
		$options['paypal_mode']      = in_array( $paypal_mode, array( 'sandbox', 'live' ), true ) ? $paypal_mode : 'sandbox';
		$options['paypal_client_id'] = isset( $input['paypal_client_id'] ) ? sanitize_text_field( wp_unslash( $input['paypal_client_id'] ) ) : '';
		$options['paypal_secret']    = isset( $input['paypal_secret'] ) ? sanitize_text_field( wp_unslash( $input['paypal_secret'] ) ) : '';

		$options['stripe_enabled']        = ! empty( $input['stripe_enabled'] ) ? 1 : 0;
		$options['stripe_pk']             = isset( $input['stripe_pk'] ) ? sanitize_text_field( wp_unslash( $input['stripe_pk'] ) ) : '';
		$options['stripe_sk']             = isset( $input['stripe_sk'] ) ? sanitize_text_field( wp_unslash( $input['stripe_sk'] ) ) : '';
		$options['stripe_webhook_secret'] = isset( $input['stripe_webhook_secret'] ) ? sanitize_text_field( wp_unslash( $input['stripe_webhook_secret'] ) ) : '';

		return $options;
	}
}
