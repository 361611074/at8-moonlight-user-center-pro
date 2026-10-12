<?php
/**
 * License bridge to the embedded AT8 License SDK.
 *
 * Since 1.1.0 all licensing (activation, domain binding, daily re-checks,
 * grace periods, deactivation and license-gated updates) is owned by the
 * embedded SDK (sdk/at8-license-sdk.php) talking to the vendor license
 * center at https://www.at8.fun. This class keeps the plugin-internal API
 * (AT8_MLUCP_License::is_active()) stable for the rest of the code base and
 * handles the one-time migration notice for keys issued by the retired
 * 1.0.x offline scheme.
 *
 * @package AT8_Moonlight_User_Center_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin bridge to the license SDK.
 */
class AT8_MLUCP_License {

	/**
	 * Product slug registered with the license center.
	 *
	 * @var string
	 */
	const PRODUCT = 'at8-moonlight-user-center-pro';

	/**
	 * Option key of the legacy 1.0.x offline license record.
	 *
	 * Kept only to detect old keys and show the migration notice; the value
	 * is never read for authorization anymore.
	 *
	 * @var string
	 */
	const LEGACY_OPTION_KEY = 'at8_mlucp_license';

	/**
	 * Whether Pro features are currently unlocked.
	 *
	 * Delegates to the SDK's local state (never issues an HTTP request).
	 *
	 * @return bool
	 */
	public static function is_active() {
		$license = AT8_License_SDK::license( self::PRODUCT );

		return $license ? $license->is_valid() : false;
	}

	/**
	 * Human-readable license state for display.
	 *
	 * @return string Localized status label from the SDK.
	 */
	public static function status_label() {
		$license = AT8_License_SDK::license( self::PRODUCT );

		return $license ? $license->status_label() : __( 'Not activated', 'at8-moonlight-user-center-pro' );
	}

	/**
	 * Warn once when a legacy offline key exists but the SDK is not active.
	 *
	 * The 1.0.x scheme validated keys locally with an embedded HMAC secret;
	 * those keys are not recognized by the license center, so they must be
	 * re-issued there. Nothing is deleted: the old record survives until the
	 * site owner activates a center-issued key or removes the plugin.
	 *
	 * @return void
	 */
	public static function maybe_legacy_notice() {
		if ( self::is_active() ) {
			return;
		}

		$legacy = get_option( self::LEGACY_OPTION_KEY, array() );

		if ( ! is_array( $legacy ) || empty( $legacy['key'] ) ) {
			return;
		}

		add_action(
			'admin_notices',
			function () {
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}

				printf(
					'<div class="notice notice-warning is-dismissible"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
					esc_html__( 'License format changed in version 1.1.0.', 'at8-moonlight-user-center-pro' ),
					esc_html__( 'Keys issued before this version are no longer valid. Please get a new license code from the license center and activate it.', 'at8-moonlight-user-center-pro' ),
					esc_url( admin_url( 'admin.php?page=at8-mlucp-license' ) ),
					esc_html__( 'Manage license', 'at8-moonlight-user-center-pro' )
				);
			}
		);
	}
}
