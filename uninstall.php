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

	// The welcome screen's own three flags.
	//
	// They are interface state, not the shop's data, which is why they sit
	// BEFORE any “remove data on uninstall” guard: whatever a shop chose to
	// keep, a reinstall has to greet it again. `includes/dahu-welcome.php`
	// writes them with update_option(), so they outlive the plugin unless
	// they are deleted here — and the welcome screen and the guided tour
	// would then stay silent for good, with nothing on screen to explain why.
	delete_option( 'dahu_welcome_seen_dahu-store-credit' );
	delete_option( 'dahu_welcome_pending_dahu-store-credit' );
	delete_option( 'dahu_welcome_tour_dahu-store-credit' );

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

	// Coupon metadata. Coupons are posts under HPOS exactly as they were
	// before it, so post meta is the whole story for these two.
	foreach ( array( '_dahu_store_credit', '_dahu_store_credit_history' ) as $meta_key ) {
		delete_post_meta_by_key( $meta_key );
	}

	/*
	 * Order markers, under BOTH storages.
	 *
	 * The two below are written with WC_Order::update_meta_data(), so where
	 * they land depends on whether the shop runs high-performance order
	 * storage: in wp_postmeta on the legacy storage, in wc_orders_meta on
	 * HPOS. delete_post_meta_by_key() reaches only the first, so on an HPOS
	 * shop — which this plugin declares itself compatible with — a wipe left
	 * every processed and restored marker behind. Reinstalling then found
	 * orders already marked as processed and carried nothing forward on them,
	 * which is a credit silently not given back.
	 *
	 * The HPOS table is addressed directly because the classes that would
	 * answer for it are not loaded during an uninstall, and it is tested for
	 * rather than assumed: a shop that never enabled HPOS does not have it.
	 */
	$order_markers = array( '_dahu_store_credit_processed', '_dahu_store_credit_restored' );

	foreach ( $order_markers as $meta_key ) {
		delete_post_meta_by_key( $meta_key );
	}

	$orders_meta = $wpdb->prefix . 'wc_orders_meta';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall routine, no caching layer available.
	$has_orders_meta = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $orders_meta ) ) );

	if ( $has_orders_meta === $orders_meta ) {
		foreach ( $order_markers as $meta_key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own-prefix table name, value prepared.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$orders_meta} WHERE meta_key = %s", $meta_key ) );
		}
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
