<?php
/**
 * Self-hosted update channel.
 *
 * The Pro plugin is not distributed through WordPress.org, so it checks its own
 * update endpoint. The updater is deliberately inert until a license server is
 * configured: without one there is nothing to check against, and injecting a
 * fake "no update" state would only confuse the Plugins screen.
 *
 * The package URL returned by the server must be signed and short-lived; this
 * client never stores the URL beyond the current request.
 *
 * @package AT8_Moonlight_User_Center_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Update checker for the self-hosted channel.
 */
class AT8_MLUCP_Updater {

	/**
	 * Transient key for the update check result.
	 *
	 * @var string
	 */
	const CACHE_KEY = 'at8_mlucp_update_check';

	/**
	 * How often the update endpoint is queried.
	 *
	 * @var int
	 */
	const CHECK_INTERVAL = 12 * HOUR_IN_SECONDS;

	/**
	 * Singleton instance.
	 *
	 * @var AT8_MLUCP_Updater|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUCP_Updater
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
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );
	}

	/**
	 * Whether the update channel can work at all.
	 *
	 * @return bool
	 */
	protected static function channel_ready() {
		return AT8_MLUCP_License::uses_server() && AT8_MLUCP_License::is_active();
	}

	/**
	 * Inject a Pro update into the update transient.
	 *
	 * @param object $transient Update plugins transient.
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) || ! self::channel_ready() ) {
			return $transient;
		}

		if ( ! isset( $transient->checked ) || ! is_array( $transient->checked ) ) {
			return $transient;
		}

		if ( ! isset( $transient->checked[ plugin_basename( AT8_MLUCP_PLUGIN_FILE ) ] ) ) {
			return $transient;
		}

		$remote = $this->fetch_remote();

		if ( ! $remote || empty( $remote['version'] ) ) {
			return $transient;
		}

		if ( version_compare( (string) $remote['version'], AT8_MLUCP_VERSION, '<=' ) ) {
			return $transient;
		}

		$transient->response[ plugin_basename( AT8_MLUCP_PLUGIN_FILE ) ] = (object) array(
			'slug'        => 'at8-moonlight-user-center-pro',
			'plugin'      => plugin_basename( AT8_MLUCP_PLUGIN_FILE ),
			'new_version' => (string) $remote['version'],
			'url'         => isset( $remote['url'] ) ? (string) $remote['url'] : '',
			'package'     => isset( $remote['package'] ) ? (string) $remote['package'] : '',
			'tested'      => isset( $remote['tested'] ) ? (string) $remote['tested'] : '',
			'requires'    => isset( $remote['requires'] ) ? (string) $remote['requires'] : '',
		);

		return $transient;
	}

	/**
	 * Provide the plugin information popup.
	 *
	 * @param false|object|array $result Result from another filter.
	 * @param string             $action API action.
	 * @param object             $args  Request arguments.
	 * @return false|object
	 */
	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'at8-moonlight-user-center-pro' !== $args->slug ) {
			return $result;
		}

		if ( ! self::channel_ready() ) {
			return $result;
		}

		$remote = $this->fetch_remote();

		if ( ! $remote || empty( $remote['version'] ) ) {
			return $result;
		}

		return (object) array(
			'name'            => 'AT8 Moonlight User Center Pro',
			'slug'            => 'at8-moonlight-user-center-pro',
			'version'         => (string) $remote['version'],
			'author'          => '漫步白月光',
			'author_profile'  => 'https://www.at8.fun/',
			'homepage'        => 'https://www.at8.fun/',
			'requires'        => isset( $remote['requires'] ) ? (string) $remote['requires'] : '5.8',
			'tested'          => isset( $remote['tested'] ) ? (string) $remote['tested'] : '',
			'download_link'   => isset( $remote['package'] ) ? (string) $remote['package'] : '',
			'sections'        => array(
				'description' => isset( $remote['changelog'] ) ? wp_kses_post( (string) $remote['changelog'] ) : '',
			),
			'last_updated'    => isset( $remote['released'] ) ? (string) $remote['released'] : '',
		);
	}

	/**
	 * Query the update endpoint, with caching.
	 *
	 * @return array|null Decoded response or null on failure.
	 */
	protected function fetch_remote() {
		$cached = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$license = AT8_MLUCP_License::get_record();

		if ( '' === $license['key'] ) {
			return null;
		}

		$response = wp_remote_post(
			trailingslashit( AT8_MLUCP_License::get_server() ) . 'update',
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => array(
					'key'     => $license['key'],
					'product' => 'at8-moonlight-user-center-pro',
					'site'    => AT8_MLUCP_License::site_signature(),
					'version' => AT8_MLUCP_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		set_transient( self::CACHE_KEY, $data, self::CHECK_INTERVAL );

		return $data;
	}

	/**
	 * Drop the cached check after an upgrade ran.
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $hook_extra Update metadata.
	 * @return void
	 */
	public function clear_cache( $upgrader = null, $hook_extra = array() ) { // phpcs:ignore Universal.CodeAnalysis.Commenting.MissingComment -- Core action signature.
		delete_transient( self::CACHE_KEY );
	}
}
