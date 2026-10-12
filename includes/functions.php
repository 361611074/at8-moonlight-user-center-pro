<?php
/**
 * Pro helper functions.
 *
 * The Pro plugin is a standalone add-on: it ships its own option set, template
 * loader and JSON responder, and it bridges to the free plugin's public API
 * (page URLs, login URLs) through the wrappers below, so the free plugin can
 * evolve without breaking Pro.
 *
 * @package AT8_Moonlight_User_Center_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read a single Pro setting.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Value returned when the key is absent.
 * @return mixed Setting value or the supplied default.
 */
function at8_mlucp_get_option( $key, $default = '' ) {
	$options = get_option( 'at8_mlucp_options', array() );

	if ( ! is_array( $options ) || ! isset( $options[ $key ] ) ) {
		return $default;
	}

	return $options[ $key ];
}

/**
 * Read a front-end interface label.
 *
 * @param string $key     Label key.
 * @param string $default Already-translated default text.
 * @return string
 */
function at8_mlucp_ui_label( $key, $default = '' ) {
	/**
	 * Filters a Pro front-end interface label.
	 *
	 * @since 1.0.0
	 *
	 * @param string $default Already-translated default text.
	 * @param string $key     Label key.
	 */
	return (string) apply_filters( 'at8_mlucp_ui_label', $default, $key );
}

/**
 * Render a Pro template.
 *
 * Themes may override any template by creating
 * `wp-content/themes/<theme>/at8-moonlight-user-center-pro/<slug>.php`.
 *
 * @param string $slug Template slug without the `.php` extension.
 * @param array  $args Variables exposed to the template.
 * @return void
 */
function at8_mlucp_get_template( $slug, $args = array() ) {
	$slug = sanitize_file_name( $slug );

	$theme_file  = trailingslashit( get_stylesheet_directory() ) . 'at8-moonlight-user-center-pro/' . $slug . '.php';
	$plugin_file = AT8_MLUCP_PLUGIN_DIR . 'templates/' . $slug . '.php';

	$file = file_exists( $theme_file ) ? $theme_file : $plugin_file;

	if ( ! file_exists( $file ) ) {
		return;
	}

	/**
	 * Filters the Pro template arguments before the template is rendered.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $args Template arguments.
	 * @param string $slug Template slug.
	 */
	$args = apply_filters( 'at8_mlucp_template_args', $args, $slug );
	$args = is_array( $args ) ? $args : array();

	/*
	 * Pro templates use plain top-level variables (as delivered). Existing
	 * variables win (EXTR_SKIP) so nothing can clobber the loader's own
	 * locals; the raw array stays available as $args for theme overrides.
	 */
	extract( $args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- template contract.

	include $file;
}

/**
 * Send a JSON response and stop.
 *
 * @param bool   $success Whether the request succeeded.
 * @param string $message Human readable message.
 * @param array  $data    Optional extra payload.
 * @return void
 */
function at8_mlucp_send_json( $success, $message, $data = array() ) {
	wp_send_json(
		array(
			'success' => (bool) $success,
			'message' => (string) $message,
			'data'    => is_array( $data ) ? $data : array(),
		)
	);
}

/**
 * Translate a WP_Error raised by the WordPress user APIs.
 *
 * @param WP_Error $error Error object.
 * @return string
 */
function at8_mlucp_translate_wp_error( $error ) {
	if ( ! is_wp_error( $error ) ) {
		return '';
	}

	$map = array(
		'invalid_username'     => __( 'That username does not exist.', 'at8-moonlight-user-center-pro' ),
		'incorrect_password'   => __( 'Incorrect username or password.', 'at8-moonlight-user-center-pro' ),
		'empty_username'       => __( 'Please enter a username.', 'at8-moonlight-user-center-pro' ),
		'empty_password'       => __( 'Please enter a password.', 'at8-moonlight-user-center-pro' ),
		'existing_user_login'  => __( 'That username is already taken.', 'at8-moonlight-user-center-pro' ),
		'existing_user_email'  => __( 'That email address is already registered.', 'at8-moonlight-user-center-pro' ),
		'invalid_email'        => __( 'That email address is not valid.', 'at8-moonlight-user-center-pro' ),
	);

	$code = $error->get_error_code();

	return isset( $map[ $code ] ) ? $map[ $code ] : $error->get_error_message();
}

/**
 * Account dashboard URL, bridged from the free plugin.
 *
 * @return string
 */
function at8_mlucp_get_account_url() {
	if ( function_exists( 'at8_mluc_get_account_url' ) ) {
		return at8_mluc_get_account_url();
	}

	return home_url( '/' );
}

/**
 * Login URL, bridged from the free plugin.
 *
 * @param string $redirect Optional same-site URL to return to after login.
 * @return string
 */
function at8_mlucp_get_login_url( $redirect = '' ) {
	if ( function_exists( 'at8_mluc_get_login_url' ) ) {
		return at8_mluc_get_login_url( $redirect );
	}

	return wp_login_url( $redirect );
}
