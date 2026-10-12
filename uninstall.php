<?php
/**
 * Uninstall cleanup.
 *
 * Runs only when the site owner deletes the plugin from the WordPress admin,
 * never on deactivation. Every site in a multisite network is cleaned.
 *
 * What is removed: the plugin options, the license record, transients and the
 * membership meta on user accounts.
 *
 * What is deliberately kept: the order and paywall records. Those posts belong
 * to the site's business history, so removing them silently could destroy
 * accounting data. Site owners who want them gone can delete the
 * "Membership Orders" entries from the admin first.
 *
 * @package AT8_Moonlight_User_Center_Pro
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove every trace of the plugin from one site.
 *
 * @return void
 */
function at8_mlucp_uninstall_site() {
	delete_option( 'at8_mlucp_options' );
	delete_option( 'at8_mlucp_license' );
	delete_option( 'at8_mlucp_license_server' );

	global $wpdb;

	// Transients are hashed per caller, so they have to be matched by prefix.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'_transient_at8_mlucp_%',
			'_transient_timeout_at8_mlucp_%'
		)
	);

	// Membership level and expiry meta on user accounts.
	delete_metadata( 'user', 0, 'at8_mlucp_membership_level', '', true );
	delete_metadata( 'user', 0, 'at8_mlucp_membership_expires', '', true );
}

if ( is_multisite() ) {
	$at8_mlucp_sites = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $at8_mlucp_sites as $at8_mlucp_site_id ) {
		switch_to_blog( (int) $at8_mlucp_site_id );
		at8_mlucp_uninstall_site();
		restore_current_blog();
	}
} else {
	at8_mlucp_uninstall_site();
}
