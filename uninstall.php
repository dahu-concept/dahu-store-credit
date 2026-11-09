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
 * A merchant who wants them gone ticks "Delete this plugin's data when the
 * plugin is deleted" on the Store credit screen before deleting the plugin.
 *
 * @package UbacCredit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site.
 *
 * @return void
 */
function ubac_credit_uninstall_site() {

	// The welcome screen's own three flags.
	//
	// They are interface state, not the shop's data, which is why they sit
	// BEFORE any “remove data on uninstall” guard: whatever a shop chose to
	// keep, a reinstall has to greet it again. `includes/ubac-welcome.php`
	// writes them with update_option(), so they outlive the plugin unless
	// they are deleted here — and the welcome screen and the guided tour
	// would then stay silent for good, with nothing on screen to explain why.
	delete_option( 'ubac_welcome_seen_ubac-credit' );
	// Les memes clefs sous le nouveau slug : elle est construite au runtime
	// a partir du slug, donc elle a change avec lui. Sans ces trois lignes,
	// une mise a jour depuis la version Ubac laisse des orphelines que plus
	// rien ne nomme.
	delete_option( 'ubac_welcome_seen_dahuconcept-store-credit' );
	delete_option( 'ubac_welcome_pending_ubac-credit' );
	delete_option( 'ubac_welcome_pending_dahuconcept-store-credit' );
	delete_option( 'ubac_welcome_tour_ubac-credit' );
	delete_option( 'ubac_welcome_tour_dahuconcept-store-credit' );

	global $wpdb;

	/*
	 * Read BEFORE the settings array is deleted just below, out of that same
	 * array, where the box that sets it lives.
	 *
	 * Until 06/11/2026 this was a standalone option named
	 * `ubac_credit_remove_data_on_uninstall` that NOTHING anywhere wrote: the
	 * branch it guards could never run, so a shop that asked for its credits to
	 * go kept them for ever with no way to say otherwise.
	 *
	 * Compared against 'yes', never cast to bool: the value is the STRING 'no'
	 * when the box is unticked, and 'no' is truthy in PHP.
	 */
	$ubac_credit_settings = get_option( 'ubac_credit_settings', array() );
	$wipe                 = is_array( $ubac_credit_settings )
		&& isset( $ubac_credit_settings['delete_data_on_uninstall'] )
		&& 'yes' === $ubac_credit_settings['delete_data_on_uninstall'];

	delete_option( 'ubac_credit_settings' );

	// The old name goes too. Nothing in the code ever wrote it, but a merchant
	// who followed the previous header and set it by hand would otherwise keep
	// an orphan row that no longer does anything.
	delete_option( 'ubac_credit_remove_data_on_uninstall' );

	// Reserved-amount cache: always safe to drop.
	foreach ( array( '_transient_ubac_credit_reserved_', '_transient_timeout_ubac_credit_reserved_' ) as $prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall routine, no caching layer available.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	}

	if ( ! $wipe ) {
		return;
	}

	// Coupon metadata. Coupons are posts under HPOS exactly as they were
	// before it, so post meta is the whole story for these two.
	foreach (
		array(
			'_ubac_credit',
			'_ubac_credit_history',
			/* The orders whose refund has already been credited to this
			   credit. A COUPON meta, so it goes here and not with the order
			   markers further down. */
			'_ubac_credit_refunded_orders',
			/* What each order took from this credit, added in 1.2.0 with the
			   register that lives on the credit. A COUPON meta too, and for
			   the same reason: it is written by the save that moves the
			   money. */
			'_ubac_credit_taken_by_orders',
			'_ubac_credit_taken_by_order_keys',
			'_ubac_credit_given_back',
		) as $meta_key
	) {
		delete_post_meta_by_key( $meta_key );
	}

	/*
	 * Order markers, under BOTH storages.
	 *
	 * The three below are written with WC_Order::update_meta_data(), so where
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
	$order_markers = array(
		'_ubac_credit_processed',
		'_ubac_credit_restored',
		'_ubac_credit_taken',
		/* Which credits on this order have their register on the credit
		   itself, added in 1.2.0. An ORDER meta, so it belongs here and is
		   deleted from both storages. */
		'_ubac_credit_register_codes',
		/* The one-off messages already written on an order. */
		'_ubac_credit_said',
	);

	foreach ( $order_markers as $meta_key ) {
		delete_post_meta_by_key( $meta_key );
	}

	$orders_meta = $wpdb->prefix . 'wc_orders_meta';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall routine, no caching layer available.
	$has_orders_meta = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $orders_meta ) ) );

	if ( $has_orders_meta === $orders_meta ) {
		foreach ( $order_markers as $meta_key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the only interpolated part is $wpdb->prefix . 'wc_orders_meta', built above from the installation's own prefix; the meta key goes through prepare().
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$orders_meta} WHERE meta_key = %s", $meta_key ) );
		}
	}
}

if ( is_multisite() ) {
	$ubac_credit_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $ubac_credit_site_ids as $ubac_credit_site_id ) {
		switch_to_blog( $ubac_credit_site_id );
		ubac_credit_uninstall_site();
		restore_current_blog();
	}
} else {
	ubac_credit_uninstall_site();
}
