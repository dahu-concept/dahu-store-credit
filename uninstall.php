<?php
/**
 * Uninstall routine.
 *
 * Settings go. Coupon metadata does not: the "store credit" flag, the balance
 * history and the processed markers live on the merchant's own coupons and
 * orders, and an uninstall is often just a step in reinstalling. Removing them
 * would silently turn every store credit back into an ordinary coupon that is
 * consumed whole — a change nobody asked for, affecting real customer balances.
 *
 * A merchant who wants them gone sets `dahu_store_credit_remove_data_on_uninstall`
 * to a truthy value before deleting the plugin.
 *
 * @package DahuStoreCredit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site.
 *
 * @return void
 */
function dahu_store_credit_uninstall_site() {

	global $wpdb;

	$wipe = (bool) get_option( 'dahu_store_credit_remove_data_on_uninstall', false );

	delete_option( 'dahu_store_credit_settings' );
	delete_option( 'dahu_store_credit_remove_data_on_uninstall' );

	// Reserved-amount cache: always safe to drop.
	foreach ( array( '_transient_dahu_store_credit_reserved_', '_transient_timeout_dahu_store_credit_reserved_' ) as $prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall routine, no caching layer available.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	}

	if ( ! $wipe ) {
		return;
	}

	foreach ( array( '_dahu_store_credit', '_dahu_store_credit_history', '_dahu_store_credit_processed', '_dahu_store_credit_restored' ) as $meta_key ) {
		delete_post_meta_by_key( $meta_key );
	}
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		dahu_store_credit_uninstall_site();
		restore_current_blog();
	}
} else {
	dahu_store_credit_uninstall_site();
}
