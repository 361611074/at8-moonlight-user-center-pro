<?php
/**
 * Plugin Name:       AT8 Moonlight User Center Pro
 * Plugin URI:        https://www.at8.fun/
 * Description:       Commercial add-on for AT8 Moonlight User Center: membership levels, paywall, hidden content, membership payments (bank transfer, PayPal, Stripe), demo videos, downloadable materials, purchases, social login and Elementor widgets. Requires the free plugin to be active.
 * Version:          1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            漫步白月光
 * Author URI:        https://www.at8.fun/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       at8-moonlight-user-center-pro
 * Domain Path:       /languages
 * Update URI:        https://www.at8.fun/
 *
 * @package AT8_Moonlight_User_Center_Pro
 */

defined( 'ABSPATH' ) || exit;

/*
 * Every constant below is namespaced with the AT8_MLUCP_ prefix, which no other
 * plugin on this site defines. The autoloader resolves classes relative to
 * __DIR__ (never to a shared constant), so a constant defined elsewhere can
 * never redirect this plugin's class loading.
 */
define( 'AT8_MLUCP_VERSION', '1.0.0' );
define( 'AT8_MLUCP_PLUGIN_FILE', __FILE__ );
define( 'AT8_MLUCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AT8_MLUCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AT8_MLUCP_PLUGIN_DIR . 'includes/functions.php';

/**
 * Register the plugin autoloader.
 *
 * Maps `AT8_MLUCP_Foo_Bar` to `includes/class-foo-bar.php`.
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
function at8_mlucp_autoload( $class_name ) {
	if ( 0 !== strpos( $class_name, 'AT8_MLUCP_' ) ) {
		return;
	}

	$relative = substr( $class_name, strlen( 'AT8_MLUCP_' ) );
	$file     = __DIR__ . '/includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';

	if ( is_readable( $file ) ) {
		require_once $file;
	}
}
spl_autoload_register( 'at8_mlucp_autoload' );

/*
 * Translations are loaded by WordPress itself: since 4.6 core fetches the
 * catalog from the translate server, so an explicit load_plugin_textdomain()
 * call is unnecessary here.
 */

/**
 * Boot the Pro plugin.
 *
 * The dependency direction is strictly Pro → Free and one-way:
 *
 * - Free missing: an admin notice explains the requirement; nothing else runs
 *   and no fatal error is possible.
 * - License invalid: only the license screen and the settings screen load.
 *   Membership, paywall, payments, OAuth, … stay completely dormant.
 * - License valid: every commercial module boots and flips the free plugin's
 *   `at8_mluc_is_pro` bridge filter.
 *
 * @return void
 */
function at8_mlucp_bootstrap() {
	if ( ! function_exists( 'at8_mluc_get_option' ) || ! defined( 'AT8_MLUC_VERSION' ) ) {
		add_action(
			'admin_notices',
			function () {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'AT8 Moonlight User Center Pro requires the free plugin AT8 Moonlight User Center to be installed and active.', 'at8-moonlight-user-center-pro' )
				);
			}
		);
		return;
	}

	// The license screen must work regardless of the license state.
	AT8_MLUCP_License::get_instance();

	if ( ! AT8_MLUCP_License::is_active() ) {
		AT8_MLUCP_Settings::get_instance();
		return;
	}

	/**
	 * Fires once AT8 Moonlight User Center Pro has booted with a valid license.
	 *
	 * @since 1.0.0
	 */
	do_action( 'at8_mlucp_loaded' );

	AT8_MLUCP_Settings::get_instance();
	AT8_MLUCP_Assets::get_instance();
	AT8_MLUCP_Membership::get_instance();
	AT8_MLUCP_Membership_Tab::get_instance();
	AT8_MLUCP_Payments::get_instance();
	AT8_MLUCP_Purchases::get_instance();
	AT8_MLUCP_Paywall::get_instance();
	AT8_MLUCP_Hidecontent::get_instance();
	AT8_MLUCP_Video::get_instance();
	AT8_MLUCP_Material::get_instance();
	AT8_MLUCP_OAuth::get_instance();
	AT8_MLUCP_Editor_Button::get_instance();
	AT8_MLUCP_Updater::get_instance();

	if ( did_action( 'elementor/loaded' ) ) {
		AT8_MLUCP_Elementor::get_instance();
	}

	add_filter(
		'at8_mluc_is_pro',
		function () {
			return AT8_MLUCP_License::is_active();
		}
	);
}
add_action( 'plugins_loaded', 'at8_mlucp_bootstrap', 6 );

register_activation_hook( __FILE__, array( 'AT8_MLUCP_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AT8_MLUCP_Activator', 'deactivate' ) );
