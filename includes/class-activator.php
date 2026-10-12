<?php
/**
 * Activation / deactivation logic.
 *
 * Activation only writes default settings; it never creates content or
 * capabilities. Deactivation leaves all data in place so the plugin can be
 * re-activated without losing anything.
 *
 * @package AT8_Moonlight_User_Center_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Activator.
 */
class AT8_MLUCP_Activator {

	/**
	 * Default option values written on first activation.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'free_level_enabled'      => 1,
			'manual_enabled'          => 1,
			'pay_currency_code'       => 'USD',
			'pay_currency_symbol'     => '$',
			'pay_manual_instructions' => '',
			'order_auto_close_hours'  => 72,
			'membership_levels'       => array(),
		);
	}

	/**
	 * Write default settings (existing values are never overwritten).
	 *
	 * @return void
	 */
	public static function activate() {
		$options = get_option( 'at8_mlucp_options', array() );

		if ( ! is_array( $options ) ) {
			$options = array();
		}

		foreach ( self::defaults() as $key => $value ) {
			if ( ! isset( $options[ $key ] ) ) {
				$options[ $key ] = $value;
			}
		}

		update_option( 'at8_mlucp_options', $options );
	}

	/**
	 * Flush rewrite rules for the custom post types.
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
