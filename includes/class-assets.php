<?php
/**
 * Front-end and admin asset loading for Pro.
 *
 * The stylesheet is the one the paywall, hidecontent, membership and purchase
 * cards depend on, so it loads on every screen that can render any of them:
 * shortcode pages, the account dashboard (Free's page), the Pro post type
 * archives and — as a safety net for page-builder templates that never pass
 * through the shortcode detection — the whole front end.
 *
 * @package AT8_Moonlight_User_Center_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Conditional asset registration.
 */
class AT8_MLUCP_Assets {

	/**
	 * Pro shortcodes that need front-end assets when present.
	 *
	 * @var string[]
	 */
	const SHORTCODES = array(
		'at8_mlucp_purchases',
		'at8_mlucp_videos',
		'at8_mlucp_video',
		'at8_mlucp_materials',
		'at8_mlucp_downloads',
	);

	/**
	 * Singleton instance.
	 *
	 * @var AT8_MLUCP_Assets|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUCP_Assets
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
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
	}

	/**
	 * Enqueue front-end assets when they may be needed.
	 *
	 * @return void
	 */
	public function enqueue() {
		wp_enqueue_style(
			'at8-mlucp-frontend',
			AT8_MLUCP_PLUGIN_URL . 'assets/css/at8-mlucp.css',
			array( 'dashicons' ),
			AT8_MLUCP_VERSION
		);

		if ( ! $this->should_enqueue() ) {
			return;
		}

		wp_enqueue_script(
			'at8-mlucp-frontend',
			AT8_MLUCP_PLUGIN_URL . 'assets/js/at8-mlucp.js',
			array( 'jquery' ),
			AT8_MLUCP_VERSION,
			true
		);

		wp_localize_script(
			'at8-mlucp-frontend',
			'AT8_MLUCP',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'at8_mlucp_nonce' ),
			)
		);

		wp_localize_script(
			'at8-mlucp-frontend',
			'at8_mlucp_i18n',
			array(
				'op_failed'  => at8_mlucp_ui_label( 'js_op_failed', __( 'Operation failed.', 'at8-moonlight-user-center-pro' ) ),
				'processing' => at8_mlucp_ui_label( 'js_processing', __( 'Processing…', 'at8-moonlight-user-center-pro' ) ),
			)
		);
	}

	/**
	 * Decide whether the interactive script is needed on this request.
	 *
	 * @return bool
	 */
	protected function should_enqueue() {
		if ( is_admin() ) {
			return false;
		}

		foreach ( self::SHORTCODES as $shortcode ) {
			if ( is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), $shortcode ) ) {
				return true;
			}
		}

		// The account dashboard hosts the membership and purchases tabs.
		$account_page = $this->get_free_account_page_id();

		if ( $account_page && is_page( $account_page ) ) {
			return true;
		}

		if ( is_post_type_archive( array( 'at8_mlucp_demo_video', 'at8_mlucp_material' ) ) ) {
			return true;
		}

		/**
		 * Filters whether the Pro front-end script should load on this request.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $needed Whether Pro renders on this request.
		 */
		return (bool) apply_filters( 'at8_mlucp_enqueue_assets', false );
	}

	/**
	 * The free plugin's account page ID, read through its public option.
	 *
	 * @return int
	 */
	protected function get_free_account_page_id() {
		if ( function_exists( 'at8_mluc_get_option' ) ) {
			return (int) at8_mluc_get_option( 'account_page_id', 0 );
		}

		return 0;
	}

	/**
	 * Load the admin assets for the post editor screens.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! isset( $screen->post_type ) || ! in_array( $screen->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script(
			'at8-mlucp-pw-admin',
			AT8_MLUCP_PLUGIN_URL . 'assets/js/at8-mlucp-pw-admin.js',
			array( 'jquery' ),
			AT8_MLUCP_VERSION,
			true
		);

		add_action( 'admin_head', array( $this, 'print_tinymce_i18n' ) );
	}

	/**
	 * Print the TinyMCE button strings as an inline global.
	 *
	 * @return void
	 */
	public function print_tinymce_i18n() {
		$types = array();

		if ( class_exists( 'AT8_MLUCP_Hidecontent' ) ) {
			foreach ( AT8_MLUCP_Hidecontent::$types as $key => $info ) {
				$types[] = array(
					'value' => $key,
					'label' => isset( $info['label'] ) ? (string) $info['label'] : $key,
				);
			}
		}

		$payload = array(
			'button_label' => __( 'Hidden Content', 'at8-moonlight-user-center-pro' ),
			'inserting'    => __( 'Choose a visibility rule…', 'at8-moonlight-user-center-pro' ),
			'types'        => $types,
		);

		echo '<script>window.at8_mlucp_tinymce_i18n = ' . wp_json_encode( $payload ) . ";</script>\n";
	}
}
