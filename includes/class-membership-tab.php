<?php
/**
 * Membership tab for the free plugin's account dashboard.
 *
 * The tab is registered through the free plugin's `at8_mluc_account_tabs`
 * filter — the free plugin owns the dashboard shell, Pro only contributes
 * content, so the two can never fight over the same markup.
 *
 * @package AT8_Moonlight_User_Center_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Membership dashboard tab.
 */
class AT8_MLUCP_Membership_Tab {

	/**
	 * Singleton instance.
	 *
	 * @var AT8_MLUCP_Membership_Tab|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUCP_Membership_Tab
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
		add_filter( 'at8_mluc_account_tabs', array( $this, 'register_tab' ) );
	}

	/**
	 * Add the membership tab.
	 *
	 * @param array $tabs Registered tabs.
	 * @return array
	 */
	public function register_tab( $tabs ) {
		if ( ! is_array( $tabs ) ) {
			return $tabs;
		}

		$tabs['membership'] = array(
			'title'    => at8_mlucp_ui_label( 'tab_membership', __( 'Membership', 'at8-moonlight-user-center-pro' ) ),
			'icon'     => 'dashicons-star-filled',
			'callback' => array( $this, 'tab_membership' ),
		);

		return $tabs;
	}

	/**
	 * Render the membership tab.
	 *
	 * @return void
	 */
	public function tab_membership() {
		$user_id = get_current_user_id();

		at8_mlucp_get_template(
			'account-membership',
			array(
				'user_id' => $user_id,
				'level'   => AT8_MLUCP_Membership::get_user_level( $user_id ),
				'expires' => AT8_MLUCP_Membership::get_user_expires( $user_id ),
				'expired' => AT8_MLUCP_Membership::is_user_expired( $user_id ),
				'levels'  => AT8_MLUCP_Membership::get_levels(),
			)
		);

		echo '<section class="at8-mlucp-card at8-mlucp-membership-upgrade-card" aria-label="' . esc_attr( at8_mlucp_ui_label( 'mb_upgrade_title', __( 'Buy / Upgrade Membership', 'at8-moonlight-user-center-pro' ) ) ) . '">';
		echo '<h3 class="at8-mlucp-card-title">' . esc_html( at8_mlucp_ui_label( 'mb_upgrade_title', __( 'Buy / Upgrade Membership', 'at8-moonlight-user-center-pro' ) ) ) . '</h3>';

		/**
		 * Fires inside the membership tab where the purchase card belongs.
		 *
		 * AT8_MLUCP_Payments hooks here to render the purchase form.
		 *
		 * @since 1.0.0
		 */
		do_action( 'at8_mlucp_membership_purchase' );

		echo '</section>';
	}
}
