<?php
/**
 * Plugin Name:       AT8 Moonlight User Center Pro
 * Plugin URI:        https://www.at8.fun/
 * Description:       Commercial add-on for AT8 Moonlight User Center: online payments (PayPal, Stripe), tiered upgrade pricing, paywall resources, demo videos, downloadable materials and purchase gates. The user center core, social login, hidden content basics, offline payments and Elementor widgets live in the free plugin. Requires the free plugin to be active.
 * Version:          1.6.2
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
define( 'AT8_MLUCP_VERSION', '1.6.2' );
define( 'AT8_MLUCP_PLUGIN_FILE', __FILE__ );
define( 'AT8_MLUCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AT8_MLUCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AT8_MLUCP_PLUGIN_DIR . 'includes/functions.php';

/*
 * AT8 License SDK (embedded, unmodified): license activation, domain binding,
 * daily re-checks, grace periods and license-gated updates all go through the
 * shared SDK against the vendor license center. The SDK keeps its own
 * class_exists guards, so several AT8 products can coexist on one site.
 */
require_once AT8_MLUCP_PLUGIN_DIR . 'sdk/at8-license-sdk.php';

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
 *   Online gateways, paywall, videos, … stay completely dormant; the free
 *   plugin's offline payment flow keeps working on its own.
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

	/*
	 * The paywall module (1.5.0) runs on the free plugin's paywall core
	 * extension surface, which only exists from 2.4.1 on. An older core
	 * would silently drop the gate, so refuse to boot the paid modules.
	 */
	if ( version_compare( AT8_MLUC_VERSION, '2.5.0', '<' ) ) {
		add_action(
			'admin_notices',
			function () {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: Pro version, 2: required free-plugin version. */
						__( 'AT8 Moonlight User Center Pro %1$s requires the free plugin AT8 Moonlight User Center %2$s or newer. Please update the free plugin first.', 'at8-moonlight-user-center-pro' ),
						AT8_MLUCP_VERSION,
						'2.5.0'
					)
				)
			);
			}
		);
		return;
	}

	// License handling lives in the embedded SDK: it registers its own
	// activation screen (mounted under the free plugin's menu), the daily
	// re-check cron and the license-gated updater. This must run before any
	// is_valid() call.
	AT8_License_SDK::init(
		array(
			'server_url'   => 'https://www.at8.fun',
			'product'      => 'at8-moonlight-user-center-pro',
			'product_type' => 'plugin',
			'plugin_file'  => AT8_MLUCP_PLUGIN_FILE,
			'buy_url'      => 'https://www.at8.fun/store/',
			// Literal titles: the SDK registers its page on admin_menu, and
			// translating here (plugins_loaded) would trigger a too-early
			// textdomain load notice on WP 6.7+.
			'menu'         => array(
				'parent'     => 'at8-mluc-settings',
				'page_title' => 'License（Pro）',
				'menu_title' => 'License（Pro）',
			),
			'menu_slug'    => 'at8-mlucp-license',
			'text_domain'  => 'at8-moonlight-user-center-pro',
			'update'       => true,
		)
	);

	// Migration notice for keys issued by the retired offline scheme (1.0.x):
	// those keys cannot be validated by the license center and must be
	// re-issued from it.
	AT8_MLUCP_License::maybe_legacy_notice();

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
	AT8_MLUCP_Gateways::get_instance();
	AT8_MLUCP_Purchases::get_instance();
	AT8_MLUCP_Paywall::get_instance();
	AT8_MLUCP_Upgrade_Tiers::get_instance();
	AT8_MLUCP_Hidecontent::get_instance();
	AT8_MLUCP_Video::get_instance();
	AT8_MLUCP_Material::get_instance();
	AT8_MLUCP_Editor_Button::get_instance();

	/*
	 * Pro adds capabilities (online gateways, tier ladders, paywall
	 * resources, level and purchase content gates). It no longer raises any
	 * free-plugin ceiling: since Free 2.5.0 there are no artificial caps to
	 * lift, and the old `at8_mluc_membership_level_limit`,
	 * `at8_mluc_limit_avatar_count` and `at8_mluc_limit_gateway_count`
	 * filters are gone from the free plugin.
	 */
	add_filter( 'at8_mluc_is_pro', 'at8_mlucp_is_pro' );

	// Document Pro-only shortcodes in the shared cheat sheet.
	add_filter( 'at8_mluc_shortcode_reference', 'at8_mlucp_shortcode_reference' );

	// Keep the upgrade table honest about what this installation really offers.
	add_filter( 'at8_mluc_upgrade_features', 'at8_mlucp_upgrade_features' );
}

/**
 * Report whether a valid Pro licence is active.
 *
 * @return bool
 */
function at8_mlucp_is_pro() {
	return AT8_MLUCP_License::is_active();
}

/**
 * Append the Pro shortcodes to the reference list.
 *
 * @param array<int, array<string, string>> $rows Existing rows.
 * @return array<int, array<string, string>>
 */
function at8_mlucp_shortcode_reference( $rows ) {
	$rows   = is_array( $rows ) ? $rows : array();
	$domain = 'at8-moonlight-user-center-pro';

	$rows[] = array(
		'code'  => '[at8_mlucp_purchases]',
		'label' => __( 'Purchase history', $domain ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- intentional: strings belong to the Pro text domain.
	);
	$rows[] = array(
		'code'  => '[at8_mlucp_videos]',
		'label' => __( 'Demo video library', $domain ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
	);
	$rows[] = array(
		'code'  => '[at8_mlucp_materials]',
		'label' => __( 'Downloadable materials', $domain ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
	);
	$rows[] = array(
		'code'  => '[at8_mlucp_hide type="vip1"]',
		'label' => __( 'Members-only content', $domain ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
	);

	return $rows;
}

/**
 * Refresh the "Pro" column with what this installation can really do.
 *
 * Since Free 2.5.0 removed every artificial cap there are no ceilings
 * left to lift, so the only row that depends on this site's setup is the
 * gateway row: it names the online gateways that are actually configured
 * instead of repeating a fixed claim.
 *
 * @param array<string, array<string, string>> $features Comparison rows.
 * @return array<string, array<string, string>>
 */
function at8_mlucp_upgrade_features( $features ) {
	if ( ! is_array( $features ) ) {
		return $features;
	}

	$domain = 'at8-moonlight-user-center-pro';
	$online = array();

	if ( class_exists( 'AT8_MLUCP_PayPal' ) && AT8_MLUCP_PayPal::enabled() ) {
		$online[] = __( 'PayPal', $domain ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- intentional: strings belong to the Pro text domain.
	}

	if ( class_exists( 'AT8_MLUCP_Stripe' ) && AT8_MLUCP_Stripe::enabled() ) {
		$online[] = __( 'Stripe', $domain ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- intentional: strings belong to the Pro text domain.
	}

	if ( ! isset( $features['gateways'] ) || ! is_array( $features['gateways'] ) ) {
		return $features;
	}

	if ( 2 === count( $online ) ) {
		// Both shelves are live, so the free plugin's default claim already holds.
		return $features;
	}

	if ( $online ) {
		$features['gateways']['pro'] = sprintf(
			/* translators: %s: comma-separated names of the configured online gateways. */
			__( 'Adds %s', $domain ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- intentional: strings belong to the Pro text domain.
			implode( ', ', $online )
		);
	} else {
		$features['gateways']['pro'] = __( 'Adds online gateways once they are configured', $domain ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- intentional: strings belong to the Pro text domain.
	}

	return $features;
}
add_action( 'plugins_loaded', 'at8_mlucp_bootstrap', 6 );

register_activation_hook( __FILE__, array( 'AT8_MLUCP_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AT8_MLUCP_Activator', 'deactivate' ) );
