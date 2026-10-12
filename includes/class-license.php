<?php
/**
 * License management: activation, deactivation and validation.
 *
 * Two delivery modes, chosen by the site owner in the Pro settings screen:
 *
 * 1. Offline mode (default): the key itself carries a signature issued by the
 *    vendor. Validation is fully local — no request leaves the site. Convenient
 *    for hand-delivered licenses; the signature deters hand-crafted keys but,
 *    like every purely local scheme, cannot stop a determined attacker.
 * 2. Server mode: once a license server URL is configured, activation and
 *    heartbeat go over REST to that server (key in the POST body, never in the
 *    query string). Results are cached for 12 hours. A server that cannot be
 *    reached is NOT the same as a rejected license: the site keeps its Pro
 *    features for a 14-day grace period, and only an explicit rejection
 *    disables them immediately.
 *
 * @package AT8_Moonlight_User_Center_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * License client.
 */
class AT8_MLUCP_License {

	/**
	 * Option key holding the license record.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'at8_mlucp_license';

	/**
	 * Option key holding the license server URL (empty = offline mode).
	 *
	 * @var string
	 */
	const SERVER_OPTION_KEY = 'at8_mlucp_license_server';

	/**
	 * Grace period in seconds after the last successful server check.
	 *
	 * @var int
	 */
	const GRACE_PERIOD = 14 * DAY_IN_SECONDS;

	/**
	 * How long a server check result is trusted.
	 *
	 * @var int
	 */
	const CACHE_PERIOD = 12 * HOUR_IN_SECONDS;

	/**
	 * Singleton instance.
	 *
	 * @var AT8_MLUCP_License|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton.
	 *
	 * @return AT8_MLUCP_License
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
		add_action( 'admin_post_at8_mlucp_license_activate', array( $this, 'handle_activate' ) );
		add_action( 'admin_post_at8_mlucp_license_deactivate', array( $this, 'handle_deactivate' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Whether Pro features are currently unlocked.
	 *
	 * @return bool
	 */
	public static function is_active() {
		$license = self::get_record();

		if ( empty( $license['key'] ) ) {
			return false;
		}

		$status = isset( $license['status'] ) ? $license['status'] : '';
		$expires = isset( $license['expires'] ) ? (int) $license['expires'] : 0;

		if ( 'valid' === $status ) {
			return 0 === $expires || $expires > time();
		}

		// A server that could not be reached must not disable paid features:
		// keep them alive until the grace period after the last good check.
		if ( 'unreachable' === $status ) {
			$last_ok = isset( $license['last_ok'] ) ? (int) $license['last_ok'] : 0;

			return $last_ok > 0 && ( time() - $last_ok ) < self::GRACE_PERIOD;
		}

		return false;
	}

	/**
	 * Human-readable license state for display.
	 *
	 * @return string One of active, grace, expired, revoked, invalid, none.
	 */
	public static function get_state() {
		$license = self::get_record();

		if ( empty( $license['key'] ) ) {
			return 'none';
		}

		$status = isset( $license['status'] ) ? $license['status'] : '';
		$expires = isset( $license['expires'] ) ? (int) $license['expires'] : 0;

		if ( 'valid' === $status ) {
			return ( 0 === $expires || $expires > time() ) ? 'active' : 'expired';
		}

		if ( 'unreachable' === $status ) {
			return self::is_active() ? 'grace' : 'expired';
		}

		if ( 'revoked' === $status ) {
			return 'revoked';
		}

		return 'invalid';
	}

	/**
	 * The stored license record.
	 *
	 * @return array{key:string,status:string,expires:int,last_ok:int,checked_at:int,site:string,product:string}
	 */
	public static function get_record() {
		$license = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $license ) ) {
			$license = array();
		}

		return array_merge(
			array(
				'key'        => '',
				'status'     => '',
				'expires'    => 0,
				'last_ok'    => 0,
				'checked_at' => 0,
				'site'       => '',
				'product'    => 'at8-moonlight-user-center-pro',
			),
			$license
		);
	}

	/**
	 * Configured license server URL (empty in offline mode).
	 *
	 * @return string
	 */
	public static function get_server() {
		return trim( (string) get_option( self::SERVER_OPTION_KEY, '' ) );
	}

	/**
	 * Whether the site runs in server mode.
	 *
	 * @return bool
	 */
	public static function uses_server() {
		return '' !== self::get_server() && wp_http_validate_url( self::get_server() );
	}

	/**
	 * Register the license screen under the free plugin's menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'at8-mluc-settings',
			__( 'License（Pro）', 'at8-moonlight-user-center-pro' ),
			__( 'License（Pro）', 'at8-moonlight-user-center-pro' ),
			'manage_options',
			'at8-mlucp-license',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the license screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$license = self::get_record();
		$state   = self::get_state();
		$server  = self::get_server();

		$state_labels = array(
			'active'   => __( 'Active', 'at8-moonlight-user-center-pro' ),
			'grace'    => __( 'Active (server unreachable, grace period)', 'at8-moonlight-user-center-pro' ),
			'expired'  => __( 'Expired', 'at8-moonlight-user-center-pro' ),
			'revoked'  => __( 'Revoked', 'at8-moonlight-user-center-pro' ),
			'invalid'  => __( 'Invalid key', 'at8-moonlight-user-center-pro' ),
			'none'     => __( 'Not activated', 'at8-moonlight-user-center-pro' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'License（Pro）', 'at8-moonlight-user-center-pro' ); ?></h1>

			<table class="widefat striped" style="max-width:760px;">
				<tbody>
					<tr>
						<th style="width:200px;"><?php esc_html_e( 'Product', 'at8-moonlight-user-center-pro' ); ?></th>
						<td><code>at8-moonlight-user-center-pro</code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'State', 'at8-moonlight-user-center-pro' ); ?></th>
						<td><strong><?php echo esc_html( isset( $state_labels[ $state ] ) ? $state_labels[ $state ] : $state ); ?></strong></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'License Key', 'at8-moonlight-user-center-pro' ); ?></th>
						<td><code><?php echo esc_html( '' !== $license['key'] ? $license['key'] : '—' ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Expires', 'at8-moonlight-user-center-pro' ); ?></th>
						<td>
							<?php
							if ( $license['expires'] ) {
								echo esc_html( wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $license['expires'] ) );
							} else {
								esc_html_e( 'Never', 'at8-moonlight-user-center-pro' );
							}
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Bound site', 'at8-moonlight-user-center-pro' ); ?></th>
						<td><?php echo esc_html( '' !== $license['site'] ? $license['site'] : '—' ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Mode', 'at8-moonlight-user-center-pro' ); ?></th>
						<td>
							<?php
							if ( self::uses_server() ) {
								esc_html_e( 'License server', 'at8-moonlight-user-center-pro' );
								echo ' — <code>' . esc_url( $server ) . '</code>';
							} else {
								esc_html_e( 'Offline (signed key)', 'at8-moonlight-user-center-pro' );
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Activate License', 'at8-moonlight-user-center-pro' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'at8_mlucp_license_activate' ); ?>
				<input type="hidden" name="action" value="at8_mlucp_license_activate">
				<p>
					<input type="text" class="regular-text code" name="license_key" placeholder="AT8ML-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-SIGN" autocomplete="off">
					<?php submit_button( __( 'Activate', 'at8-moonlight-user-center-pro' ), 'primary', 'submit', false ); ?>
				</p>
				<p class="description"><?php esc_html_e( 'Enter the key you received on purchase. The key binds to this site; deactivate before migrating to another domain.', 'at8-moonlight-user-center-pro' ); ?></p>
			</form>

			<?php if ( '' !== $license['key'] ) : ?>
				<h2><?php esc_html_e( 'Deactivate License', 'at8-moonlight-user-center-pro' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Deactivating disables every Pro feature immediately. Continue?', 'at8-moonlight-user-center-pro' ) ); ?>');">
					<?php wp_nonce_field( 'at8_mlucp_license_deactivate' ); ?>
					<input type="hidden" name="action" value="at8_mlucp_license_deactivate">
					<?php submit_button( __( 'Deactivate', 'at8-moonlight-user-center-pro' ), 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the one-shot admin notice.
	 *
	 * @return void
	 */
	public function render_notice() {
		$notice = get_transient( 'at8_mlucp_lic_notice_' . get_current_user_id() );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( 'at8_mlucp_lic_notice_' . get_current_user_id() );

		$class = empty( $notice['error'] ) ? 'notice-success' : 'notice-error';

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Handle the activation form.
	 *
	 * @return void
	 */
	public function handle_activate() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'at8_mlucp_license_activate' ) ) {
			wp_die( esc_html__( 'Permission denied or security check failed.', 'at8-moonlight-user-center-pro' ) );
		}

		$redirect = admin_url( 'admin.php?page=at8-mlucp-license' );
		$key      = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		$key      = strtoupper( trim( $key ) );

		if ( '' === $key ) {
			self::set_notice( __( 'Please enter a license key.', 'at8-moonlight-user-center-pro' ), true );
			wp_safe_redirect( $redirect );
			exit;
		}

		$result = self::activate( $key );

		if ( is_wp_error( $result ) ) {
			self::set_notice( $result->get_error_message(), true );
		} else {
			self::set_notice( __( 'License activated. Pro features are now available.', 'at8-moonlight-user-center-pro' ) );

			/**
			 * Fires after a license was activated.
			 *
			 * @since 1.0.0
			 *
			 * @param string $key Activated license key.
			 */
			do_action( 'at8_mlucp_license_activated', $key );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle the deactivation form.
	 *
	 * @return void
	 */
	public function handle_deactivate() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'at8_mlucp_license_deactivate' ) ) {
			wp_die( esc_html__( 'Permission denied or security check failed.', 'at8-moonlight-user-center-pro' ) );
		}

		$license = self::get_record();
		$redirect = admin_url( 'admin.php?page=at8-mlucp-license' );

		if ( '' !== $license['key'] ) {
			$result = self::deactivate();

			if ( is_wp_error( $result ) ) {
				self::set_notice( $result->get_error_message(), true );
			} else {
				self::set_notice( __( 'License deactivated. Pro features are disabled.', 'at8-moonlight-user-center-pro' ) );

				/**
				 * Fires after a license was deactivated.
				 *
				 * @since 1.0.0
				 */
				do_action( 'at8_mlucp_license_deactivated' );
			}
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Activate a license key.
	 *
	 * @param string $key Raw key.
	 * @return true|WP_Error
	 */
	public static function activate( $key ) {
		$key = strtoupper( trim( (string) $key ) );

		if ( ! self::is_well_formed( $key ) ) {
			return new WP_Error( 'at8_mlucp_bad_key', __( 'The key format is not recognised.', 'at8-moonlight-user-center-pro' ) );
		}

		if ( self::uses_server() ) {
			$response = self::server_request( 'activate', array( 'key' => $key ) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$record = array(
				'key'        => $key,
				'status'     => 'valid',
				'expires'    => isset( $response['expires'] ) ? (int) $response['expires'] : 0,
				'last_ok'    => time(),
				'checked_at' => time(),
				'site'       => self::site_signature(),
				'product'    => 'at8-moonlight-user-center-pro',
			);

			update_option( self::OPTION_KEY, $record );

			return true;
		}

		$payload = self::verify_offline_key( $key );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		if ( ! empty( $payload['product'] ) && 'at8-moonlight-user-center-pro' !== $payload['product'] ) {
			return new WP_Error( 'at8_mlucp_wrong_product', __( 'This key belongs to a different product.', 'at8-moonlight-user-center-pro' ) );
		}

		if ( ! empty( $payload['expires'] ) && (int) $payload['expires'] < time() ) {
			return new WP_Error( 'at8_mlucp_expired', __( 'This key has expired.', 'at8-moonlight-user-center-pro' ) );
		}

		$record = array(
			'key'        => $key,
			'status'     => 'valid',
			'expires'    => isset( $payload['expires'] ) ? (int) $payload['expires'] : 0,
			'last_ok'    => time(),
			'checked_at' => time(),
			'site'       => self::site_signature(),
			'product'    => 'at8-moonlight-user-center-pro',
		);

		update_option( self::OPTION_KEY, $record );

		return true;
	}

	/**
	 * Deactivate the current license.
	 *
	 * @return true|WP_Error
	 */
	public static function deactivate() {
		$license = self::get_record();
		$error   = null;

		if ( self::uses_server() && '' !== $license['key'] ) {
			$response = self::server_request( 'deactivate', array( 'key' => $license['key'] ) );

			if ( is_wp_error( $response ) ) {
				$error = $response;
			}
		}

		delete_option( self::OPTION_KEY );

		return $error ? $error : true;
	}

	/**
	 * Whether a key is well formed.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function is_well_formed( $key ) {
		return (bool) preg_match( '/^AT8ML(-[A-Z2-7]{6}){6}-[A-F0-9]{6}$/', $key );
	}

	/**
	 * Site signature bound to a license.
	 *
	 * @return string
	 */
	public static function site_signature() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return $host ? strtolower( $host ) : '';
	}

	/**
	 * Verify an offline (signed) key and return its payload.
	 *
	 * The key layout is `AT8ML-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-XXXXXX-SIGN`,
	 * where the six payload groups carry a base32-encoded payload of the form
	 * `mlucp|<expires>` (a Unix timestamp, or 0 for a perpetual license) and
	 * SIGN is the first six characters of
	 * `hash_hmac( 'sha256', payload, secret )`.
	 *
	 * @param string $key Key.
	 * @return array|WP_Error Payload (`product`, `expires`) or error.
	 */
	protected static function verify_offline_key( $key ) {
		$parts = explode( '-', $key );

		if ( count( $parts ) !== 8 ) {
			return new WP_Error( 'at8_mlucp_bad_key', __( 'The key format is not recognised.', 'at8-moonlight-user-center-pro' ) );
		}

		$payload_raw = implode( '', array_slice( $parts, 1, 6 ) );
		$signature   = $parts[7];

		if ( 6 !== strlen( $signature ) ) {
			return new WP_Error( 'at8_mlucp_bad_key', __( 'The key format is not recognised.', 'at8-moonlight-user-center-pro' ) );
		}

		$expected = strtoupper( substr( hash_hmac( 'sha256', $payload_raw, self::secret() ), 0, 6 ) );

		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'at8_mlucp_bad_signature', __( 'The key signature is invalid.', 'at8-moonlight-user-center-pro' ) );
		}

		$decoded = self::base32_decode( $payload_raw );

		if ( '' === $decoded ) {
			return new WP_Error( 'at8_mlucp_bad_payload', __( 'The key payload could not be read.', 'at8-moonlight-user-center-pro' ) );
		}

		/*
		 * Base32 pads the payload to whole 6-character groups with 'A' (zero
		 * bits). Those bits can cross a byte boundary, so strip any trailing
		 * NUL bytes the padding introduced; the pipe format never ends in NUL.
		 */
		$decoded = rtrim( $decoded, "\0" );

		$segments = explode( '|', $decoded );

		if ( 2 !== count( $segments ) || 'mlucp' !== $segments[0] || ! preg_match( '/^\d+$/', $segments[1] ) ) {
			return new WP_Error( 'at8_mlucp_bad_payload', __( 'The key payload could not be read.', 'at8-moonlight-user-center-pro' ) );
		}

		return array(
			'product' => 'at8-moonlight-user-center-pro',
			'expires' => (int) $segments[1],
		);
	}

	/**
	 * Signing secret for offline keys.
	 *
	 * Kept in one place so it can be rotated in a future release; see the
	 * README for the honest limits of offline validation.
	 *
	 * @return string
	 */
	protected static function secret() {
		return 'at8-mlucp-offline-v1';
	}

	/**
	 * Base32 decode (RFC 4648 alphabet, no padding).
	 *
	 * @param string $input Encoded string.
	 * @return string
	 */
	protected static function base32_decode( $input ) {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$bits     = '';
		$output   = '';

		$input = strtoupper( $input );

		for ( $i = 0, $len = strlen( $input ); $i < $len; $i++ ) {
			$pos = strpos( $alphabet, $input[ $i ] );

			if ( false === $pos ) {
				return '';
			}

			$bits .= str_pad( decbin( $pos ), 5, '0', STR_PAD_LEFT );
		}

		for ( $i = 0, $len = strlen( $bits ); $i + 8 <= $len; $i += 8 ) {
			$output .= chr( (int) bindec( substr( $bits, $i, 8 ) ) );
		}

		return $output;
	}

	/**
	 * Talk to the license server.
	 *
	 * @param string $action One of activate, deactivate, validate.
	 * @param array  $body   Extra body fields.
	 * @return array|WP_Error Decoded response or error.
	 */
	protected static function server_request( $action, array $body = array() ) {
		$server = self::get_server();

		if ( ! wp_http_validate_url( $server ) ) {
			return new WP_Error( 'at8_mlucp_bad_server', __( 'The license server URL is not valid.', 'at8-moonlight-user-center-pro' ) );
		}

		$body = array_merge(
			$body,
			array(
				'product' => 'at8-moonlight-user-center-pro',
				'site'    => self::site_signature(),
				'version' => AT8_MLUCP_VERSION,
			)
		);

		$response = wp_remote_post(
			trailingslashit( $server ) . $action,
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'at8_mlucp_unreachable', __( 'The license server could not be reached.', 'at8-moonlight-user-center-pro' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return new WP_Error( 'at8_mlucp_server_error', __( 'The license server rejected the request.', 'at8-moonlight-user-center-pro' ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'at8_mlucp_bad_response', __( 'The license server sent an unreadable response.', 'at8-moonlight-user-center-pro' ) );
		}

		return $data;
	}

	/**
	 * Store a one-shot admin notice.
	 *
	 * @param string $message Message.
	 * @param bool   $error   Whether the notice is an error.
	 * @return void
	 */
	protected static function set_notice( $message, $error = false ) {
		set_transient(
			'at8_mlucp_lic_notice_' . get_current_user_id(),
			array(
				'message' => $message,
				'error'   => (bool) $error,
			),
			60
		);
	}
}
