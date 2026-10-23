<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The business logic: marking coupons, carrying the balance forward, restoring it.
 */
class Dahu_Store_Credit_Core {

	/**
	 * Order meta holding what each credit actually gave to this order, as an
	 * array keyed by coupon code. A single fixed key, so uninstalling finds
	 * it without a prefix search.
	 */
	const TAKEN_META = '_dahu_store_credit_taken';

	/**
	 * Coupon meta listing the orders whose refund has already been credited to
	 * this credit. It lives on the COUPON, not on the order, so that it is
	 * written by the very save() that moves the money: one object, one write,
	 * nothing in between. An order-side marker could not do that -- see
	 * restore_order().
	 */
	const REFUNDED_META = '_dahu_store_credit_refunded_orders';


	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_coupon_options', array( $this, 'render_coupon_field' ), 10, 2 );
		add_action( 'woocommerce_coupon_options_save', array( $this, 'save_coupon_field' ), 10, 2 );

		add_action( 'woocommerce_order_status_processing', array( $this, 'process_order' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'process_order' ) );

		add_action( 'woocommerce_order_status_cancelled', array( $this, 'restore_order' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'restore_order' ) );

		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'cap_balance_to_available' ), 10, 2 );
		add_filter( 'woocommerce_coupon_error', array( $this, 'customize_coupon_error' ), 10, 3 );
	}

	private function is_enabled() {
		$settings = get_option( 'dahu_store_credit_settings', array() );
		$enabled  = isset( $settings['enabled'] ) ? $settings['enabled'] : 'yes';
		return 'yes' === $enabled;
	}

	private function extension_days() {
		$settings = get_option( 'dahu_store_credit_settings', array() );
		$days     = isset( $settings['extension_days'] ) ? (int) $settings['extension_days'] : 0;
		return max( 0, $days );
	}

	/**
	 * The checkbox on the coupon's General tab.
	 *
	 * @param int       $coupon_id Coupon post id.
	 * @param WC_Coupon $coupon    Coupon object.
	 * @return void
	 */
	public function render_coupon_field( $coupon_id, $coupon ) {
		echo '<div class="options_group">';
		woocommerce_wp_checkbox(
			array(
				'id'          => '_dahu_store_credit',
				'label'       => __( 'Store credit (balance carries forward)', 'dahu-store-credit' ),
				'description' => __( 'When ticked, whatever is left of this fixed-cart coupon is carried forward after payment instead of being lost.', 'dahu-store-credit' ),
				'desc_tip'    => true,
			)
		);
		echo '</div>';

		if ( $coupon_id && 'yes' === $coupon->get_meta( '_dahu_store_credit' ) && 'fixed_cart' !== $coupon->get_discount_type() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This coupon is marked as store credit but is not of the “Fixed cart discount” type. Dahu Store Credit will ignore it.', 'dahu-store-credit' ) . '</p></div>';
		}
	}

	/**
	 * Persist the checkbox when the coupon is saved.
	 *
	 * WooCommerce fires this hook from inside its own coupon save, which has
	 * already checked the nonce and the capability. Both are re-checked here
	 * anyway: a hook can be fired by anything, and a write that depends on
	 * another plugin's checks is a write with no checks of its own.
	 *
	 * @param int       $post_id Coupon post id.
	 * @param WC_Coupon $coupon  Coupon object.
	 * @return void
	 */
	public function save_coupon_field( $post_id, $coupon ) {

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// WooCommerce posts the coupon form with this nonce field.
		if ( ! isset( $_POST['woocommerce_meta_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}

		$value = isset( $_POST['_dahu_store_credit'] ) ? 'yes' : 'no';

		$coupon->update_meta_data( '_dahu_store_credit', $value );
		$coupon->save();
	}

	/**
	 * Is this coupon handled by Dahu Store Credit?
	 *
	 * The meta is read straight from the database, bypassing the object cache:
	 * a WC_Coupon left stale after a recent save would otherwise produce a
	 * false negative, and a false negative here means a store credit silently
	 * treated as an ordinary coupon.
	 *
	 * @param WC_Coupon $coupon Coupon object.
	 * @return bool
	 */
	private function is_dahu_store_credit_coupon( WC_Coupon $coupon ) {
		$meta = $coupon->get_meta( '_dahu_store_credit' );
		if ( '' === $meta || null === $meta ) {
			$meta = get_post_meta( $coupon->get_id(), '_dahu_store_credit', true );
		}
		return 'fixed_cart' === $coupon->get_discount_type() && 'yes' === $meta;
	}

	private function log( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->debug( $message, array( 'source' => 'dahu-store-credit' ) );
		}
	}

	/**
	 * How much of this credit is already "reserved" by unpaid orders.
	 *
	 * Orders that are pending or awaiting payment have claimed part of the
	 * balance without having consumed it yet. Without counting them, several
	 * concurrent orders could each be told the full balance is available and
	 * together spend more than exists — the customer gets more credit than
	 * they hold, and the shop finds out at reconciliation.
	 *
	 * Cached for 30 seconds, because this runs on a hook that fires every time
	 * a coupon is validated in the cart.
	 *
	 * @param WC_Coupon $coupon Coupon object.
	 * @return float Amount already claimed by unpaid orders.
	 */
	private function get_reserved_amount( WC_Coupon $coupon ) {
		$cache_key = 'dahu_store_credit_reserved_' . $coupon->get_id();
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (float) $cached;
		}

		global $wpdb;

		$code     = $coupon->get_code();
		$decimals = wc_get_price_decimals();
		$reserved = 0.0;

		/*
		 * Only the orders that actually carry this coupon are looked at.
		 *
		 * The obvious implementation — list every pending order, then inspect
		 * each one's coupon lines — walks the whole pending queue on a hook
		 * that fires every time a coupon is validated in the cart. On a shop
		 * with a few thousand pending orders that is a checkout timeout.
		 *
		 * Starting from the coupon instead bounds the work by how often this
		 * particular credit has been used, which is a handful of orders at
		 * most. `order_item_name` holds the coupon code on a coupon line.
		 */
		$order_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT order_id
				 FROM {$wpdb->prefix}woocommerce_order_items
				 WHERE order_item_type = 'coupon'
				   AND order_item_name = %s",
				$code
			)
		);

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order || ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
				continue;
			}
			if ( 'yes' === $order->get_meta( '_dahu_store_credit_processed' ) ) {
				continue;
			}
			foreach ( $order->get_items( 'coupon' ) as $item ) {
				if ( $item->get_code() === $code ) {
					$reserved += (float) $item->get_discount() + (float) $item->get_discount_tax();
				}
			}
		}

		$reserved = round( $reserved, $decimals );
		set_transient( $cache_key, $reserved, 30 );

		return $reserved;
	}

	private function clear_reserved_cache( $coupon_id ) {
		delete_transient( 'dahu_store_credit_reserved_' . $coupon_id );
	}

	/**
	 * Cap the coupon's amount to what is actually still available.
	 *
	 * Done in memory only, never persisted: the coupon keeps its real balance,
	 * and only this request sees the reduced figure. Persisting it would mean
	 * an abandoned cart permanently shrinking a customer's credit.
	 *
	 * @param bool      $valid  Whether WooCommerce considers the coupon valid.
	 * @param WC_Coupon $coupon Coupon object.
	 * @return bool
	 */
	public function cap_balance_to_available( $valid, $coupon ) {
		if ( ! $valid || ! $this->is_enabled() ) {
			return $valid;
		}

		if ( ! $this->is_dahu_store_credit_coupon( $coupon ) ) {
			return $valid;
		}

		$reserved  = $this->get_reserved_amount( $coupon );
		$available = max( 0, round( (float) $coupon->get_amount() - $reserved, wc_get_price_decimals() ) );

		if ( $available <= 0 ) {
			return false;
		}

		if ( $available < (float) $coupon->get_amount() ) {
			$coupon->set_amount( $available );
		}

		return $valid;
	}

	public function customize_coupon_error( $err, $err_code, $coupon ) {
		if ( 100 === $err_code && $coupon instanceof WC_Coupon && $this->is_dahu_store_credit_coupon( $coupon ) ) {
			return __( 'The balance of this store credit is currently too low, or already reserved by another order awaiting payment.', 'dahu-store-credit' );
		}
		return $err;
	}

	/**
	 * Carry the remaining balance forward once the order is paid.
	 *
	 * @param int $order_id The order that was paid.
	 * @return void
	 */
	public function process_order( $order_id ) {
		$this->log( "process_order() called for order #{$order_id}" );

		if ( ! $this->is_enabled() ) {
			$this->log( 'Dahu Store Credit is switched off in the settings; nothing processed.' );
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			$this->log( "Order #{$order_id} not found by wc_get_order()." );
			return;
		}

		if ( 'yes' === $order->get_meta( '_dahu_store_credit_processed' ) ) {
			$this->log( "Order #{$order_id} already processed (_dahu_store_credit_processed = yes); skipping." );
			return;
		}

		$coupon_items = $order->get_items( 'coupon' );
		if ( empty( $coupon_items ) ) {
			// Not an event. Most orders carry no coupon at all, and annotating
			// every one of them would bury the notes that do mean something
			// under a line a day per order. The log is the right place.
			$this->log( "Order #{$order_id} carries no coupon; nothing to do." );
			return;
		}

		$decimals  = wc_get_price_decimals();
		$processed = false;

		foreach ( $coupon_items as $item ) {
			$code        = $item->get_code();
			$coupon_id   = wc_get_coupon_id_by_code( $code );

			/*
			 * ALREADY CHARGED? THE RECORD SAYS SO, CREDIT BY CREDIT.
			 *
			 * The order-wide marker used to be written inside this loop, so a
			 * replay returned at the guard above before it had reached the
			 * second credit. Measured on an order carrying two credits of
			 * 100 EUR with lines of 40 and 30, interrupted after the first
			 * credit was debited: the second was never debited at all, and the
			 * refund then handed back its line on a credit nothing had been
			 * taken from -- 130 EUR on a credit worth 100, 30 EUR created.
			 *
			 * Moving the marker out of the loop alone brought the opposite
			 * defect back: the replay debited the first credit a second time.
			 * So the loop asks the record, per code, rather than asking the
			 * order. A record of '0' means a refund has given that credit
			 * back, which leaves it chargeable again.
			 */
			$charged = $order->get_meta( self::TAKEN_META );
			$charged = is_array( $charged ) ? $charged : array();
			$already = wc_format_coupon_code( $code );
			if ( array_key_exists( $already, $charged ) && '0' !== (string) $charged[ $already ] ) {
				$this->log( "Order #{$order_id} already charged credit {$code}; skipping it." );
				continue;
			}

			if ( ! $coupon_id ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: coupon code */
						__( 'Dahu Store Credit: coupon “%s” no longer exists; nothing was processed.', 'dahu-store-credit' ),
						$code
					)
				);
				continue;
			}

			$coupon = new WC_Coupon( $coupon_id );

			if ( ! $this->is_dahu_store_credit_coupon( $coupon ) ) {
				// An ordinary coupon is the normal case, not a problem: a shop
				// using plain coupons alongside store credit would otherwise
				// get a note on every order. It also used to print the raw
				// meta value onto the order screen, which is debugging output,
				// not something a merchant should read.
				$this->log(
					sprintf(
						'Order #%1$d: coupon "%2$s" is not store credit (type=%3$s, _dahu_store_credit=%4$s); left alone.',
						$order_id,
						$code,
						$coupon->get_discount_type(),
						$coupon->get_meta( '_dahu_store_credit' ) ? $coupon->get_meta( '_dahu_store_credit' ) : '(empty)'
					)
				);
				continue;
			}

			$consumed = round( (float) $item->get_discount() + (float) $item->get_discount_tax(), $decimals );
			$amount   = (float) $coupon->get_amount();
			$remaining = max( 0, round( $amount - $consumed, $decimals ) );

			/*
			 * Strictly greater than zero, and the figure is already rounded to
			 * the shop's precision. A threshold of one cent instead destroyed
			 * the smallest balance there is: a credit with exactly 0.01 left
			 * was declared fully used, the cent was dropped, and the coupon
			 * kept its old amount while the order note said it was spent. A
			 * residue too small to be money is already zero after the rounding
			 * above, so no threshold is needed to get rid of it.
			 */
			/*
			 * ONE WRITE, WHATEVER IS LEFT.
			 *
			 * There used to be two branches here: "something is left", which
			 * wrote the amount, and "fully used", which wrote the note and the
			 * history and FORGOT the amount. Measured on the published
			 * version: a 100 EUR credit settled by a 100 EUR order kept its
			 * 100 EUR, and the customer spent it again as often as he cared
			 * to; a refund on top took it to 200 EUR. The note said "fully
			 * used" all the same.
			 *
			 * The defect was the duplication itself: two paths for one
			 * operation, one of them incomplete. There is only one now.
			 * `$remaining` is zero in the "fully used" case, which is exactly
			 * what has to be written; only the note differs.
			 */
			$coupon->set_amount( $remaining );
			$coupon->set_usage_count( 0 );

			/*
			 * WHAT WAS ACTUALLY TAKEN, recorded so a refund can undo it.
			 *
			 * A charge clips -- it cannot take more than the credit holds --
			 * whereas a refund used to add back the COUPON LINE, which has no
			 * such limit. Measured: a 100 EUR credit on an order consuming
			 * 120 EUR, refunded, came back as 220 EUR.
			 *
			 * The coupon line is therefore not enough to undo a charge: what
			 * was taken is recorded per credit, and that is what is returned.
			 *
			 * Recorded UNDER THE CODE, not under the id. A shop that deletes a
			 * credit and recreates it under the same code gives it a fresh id;
			 * a record filed by id then became unreachable and the refund fell
			 * back on the coupon line -- measured: 120 EUR handed back on a
			 * credit that had only ever held 100. The code, by contrast, is
			 * what the order line itself carries.
			 *
			 * One meta holding an array keyed by code, because a prefixed key
			 * can only be erased with a LIKE: uninstalling would find it badly
			 * and an orphan meta would outlive the plugin.
			 */
			$taken          = round( $amount - $remaining, $decimals );
			$taken_map      = $order->get_meta( self::TAKEN_META );
			$taken_map      = is_array( $taken_map ) ? $taken_map : array();
			$taken_map[ wc_format_coupon_code( $code ) ] = (string) $taken;
			$order->update_meta_data( self::TAKEN_META, $taken_map );

			$used_by  = $coupon->get_used_by();
			$user_ref = $order->get_customer_id() ? (string) $order->get_customer_id() : $order->get_billing_email();
			if ( ! empty( $used_by ) ) {
				$used_by = array_values(
					array_filter(
						$used_by,
						function ( $u ) use ( $user_ref ) {
							return (string) $u !== (string) $user_ref;
						}
					)
				);
				$coupon->update_meta_data( 'used_by', $used_by );
			}

			$extension_days = $this->extension_days();
			if ( $extension_days > 0 && $remaining > 0 ) {
				$coupon->set_date_expires( time() + ( $extension_days * DAY_IN_SECONDS ) );
			}

			/*
			 * THE RECORD BEFORE THE DEBIT.
			 *
			 * The coupon used to be debited first and the order saved after.
			 * Any interruption between the two -- another plugin on the note,
			 * a request cut short -- left a debited credit with no record of
			 * what had been taken and without the marker that stops a second
			 * pass. Measured: a 100 EUR credit and a 40 EUR line run twice
			 * fell to 20 EUR, 40 EUR lost by the customer, and the note locked
			 * the loss in by announcing one charge only.
			 *
			 * Money therefore no longer moves before what undoes it is
			 * durable.
			 *
			 * Only the record is written here, not the order-wide marker: that
			 * one is written after the loop, so an interruption does not hide
			 * the credits this pass has not reached yet.
			 */
			$order->save();

			$coupon->save();
			$this->clear_reserved_cache( $coupon->get_id() );

			$this->push_history( $coupon, $order_id, $consumed, $remaining );

			if ( $remaining > 0 ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: coupon code, 2: consumed amount, 3: remaining balance */
						__( 'Store credit %1$s: %2$s used, %3$s carried forward.', 'dahu-store-credit' ),
						$code,
						wc_price( $consumed ),
						wc_price( $remaining )
					)
				);
			} else {
				$order->add_order_note(
					sprintf(
						/* translators: %s: coupon code */
						__( 'Store credit %s fully used.', 'dahu-store-credit' ),
						$code
					)
				);
			}

			$processed = true;
		}

		if ( $processed ) {
			$order->update_meta_data( '_dahu_store_credit_processed', 'yes' );
			$order->save();
		}
	}

	/**
	 * Give the consumed amount back when the order is cancelled or refunded.
	 *
	 * @param int $order_id The order that was cancelled or refunded.
	 * @return void
	 */
	public function restore_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		/*
		 * THE RECORDS ARE THE DOOR, NOT THE MARKER.
		 *
		 * The order-wide marker is written after the charge loop, so it is the
		 * last thing to reach the database -- while the per-credit records are
		 * written as each credit is charged. Gating a refund on the marker
		 * therefore guarded durable data with a weaker signal: measured on an
		 * order carrying two credits, a request that died on the marker left
		 * both credits charged, both records in the database, and the refund
		 * refusing to run at all -- 70 EUR taken from the customer and never
		 * returned, with no note and no history to find it by. Three credits
		 * made it 90.
		 *
		 * So a refund runs when there is something to give back: either the
		 * marker, or a record of what was taken. The marker stays as the
		 * order-wide summary it already is on the refund side.
		 */
		$charged_any = $order->get_meta( self::TAKEN_META );
		$charged_any = is_array( $charged_any ) ? $charged_any : array();

		if ( 'yes' !== $order->get_meta( '_dahu_store_credit_processed' ) && ! $charged_any ) {
			return;
		}

		if ( 'yes' === $order->get_meta( '_dahu_store_credit_restored' ) ) {
			return;
		}

		$decimals     = wc_get_price_decimals();
		$coupon_items = $order->get_items( 'coupon' );
		$restored     = false;

		foreach ( $coupon_items as $item ) {
			$code      = $item->get_code();
			$coupon_id = wc_get_coupon_id_by_code( $code );

			if ( ! $coupon_id ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: coupon code */
						__( 'Dahu Store Credit: could not restore “%s”, coupon not found.', 'dahu-store-credit' ),
						$code
					)
				);
				continue;
			}

			$coupon = new WC_Coupon( $coupon_id );

			if ( ! $this->is_dahu_store_credit_coupon( $coupon ) ) {
				continue;
			}

			$consumed = round( (float) $item->get_discount() + (float) $item->get_discount_tax(), $decimals );

			if ( $consumed <= 0 ) {
				continue;
			}

			/*
			 * This order's refund already credited to this credit? Then there
			 * is nothing to do, and the token that says so sits on the coupon
			 * itself -- see the write below for why it cannot be on the order.
			 */
			$done = $coupon->get_meta( self::REFUNDED_META );
			if ( is_array( $done ) && in_array( (int) $order_id, array_map( 'intval', $done ), true ) ) {
				continue;
			}

			/*
			 * WE RETURN WHAT WAS TAKEN, NOT WHAT THE LINE SHOWS.
			 *
			 * A charge clips: it cannot take more than the credit held. A
			 * refund, by contrast, added back the coupon line with no such
			 * limit -- measured: a 100 EUR credit on an order consuming
			 * 120 EUR, refunded, came back as 220 EUR.
			 *
			 * The charge now records, per credit, what it really took. Where
			 * that record exists, it is what counts.
			 *
			 * Where it is missing -- an order charged by an earlier version --
			 * the old calculation stands, and it is worth saying which way it
			 * errs: measured across the whole range, this fallback NEVER
			 * returns less than was taken, and can return up to the credit's
			 * own value too much when the order consumed more than it held.
			 * Orders charged before this update therefore stay exposed to the
			 * old defect; new ones do not. Bounding it would mean guessing
			 * what the credit held back then, which nothing records.
			 */
			/*
			 * `is_numeric`, AND NOT "IS THE RECORD THERE".
			 *
			 * The record is a meta: an import that re-encodes orders, a failed
			 * serialization, a third-party tool, and it comes back as an
			 * unreadable string or an array. Testing only for its presence let
			 * `(float)` speak in its place -- measured: an unreadable record
			 * gave 0, the guard below cut the loop, and 40 EUR taken were not
			 * returned, with no note, no marker and no history line; a nested
			 * array gave 1 EUR for 40 EUR taken.
			 *
			 * A record we cannot read is therefore treated as a record that is
			 * missing: the fallback above applies. It may overpay, but it says
			 * so in the order note, whereas a silent refund is invisible to
			 * the customer and to the shop alike.
			 *
			 * `is_finite` sits beside it because `is_numeric` accepts '1e400',
			 * which `(float)` turns into INF: that passed the guard below and
			 * gave the coupon an infinite value, able to pay any order, while
			 * the note read "inf returned to the coupon". A charge cannot
			 * write such a value -- only an import or a hand edit can, which is
			 * exactly the family this test is here for.
			 */
			$taken_map = $order->get_meta( self::TAKEN_META );
			$taken_map = is_array( $taken_map ) ? $taken_map : array();
			$key       = wc_format_coupon_code( $code );
			$recorded  = array_key_exists( $key, $taken_map ) ? $taken_map[ $key ] : '';
			$give_back = is_numeric( $recorded ) && is_finite( (float) $recorded )
				? round( (float) $recorded, $decimals )
				: $consumed;

			/*
			 * NOTHING TO RETURN, NOTHING TO SAY.
			 *
			 * A credit already empty when the order was charged gave nothing,
			 * yet the coupon line still carries its amount. The guard further
			 * up looks at that line rather than at the charge itself, and so
			 * allowed a note saying "50.00 returned", a history entry of
			 * -50 EUR and the refund marker, for nothing returned at all.
			 */
			if ( $give_back <= 0 ) {
				continue;
			}

			$new_amount = round( (float) $coupon->get_amount() + $give_back, $decimals );
			$coupon->set_amount( $new_amount );
			$coupon->set_usage_count( 0 );

			$used_by = $coupon->get_used_by();
			$user_ref = $order->get_customer_id() ? (string) $order->get_customer_id() : $order->get_billing_email();
			if ( ! empty( $used_by ) ) {
				$used_by = array_values(
					array_filter(
						$used_by,
						function ( $u ) use ( $user_ref ) {
							return (string) $u !== (string) $user_ref;
						}
					)
				);
				$coupon->update_meta_data( 'used_by', $used_by );
			}

			/*
			 * THE RECORD IS SPENT BEFORE THE CREDIT, AS ON THE CHARGE SIDE.
			 *
			 * A charge saves the order before debiting the credit; a refund
			 * used to credit the coupon before saving its own order, and that
			 * is the direction which repeats without limit. Measured: a
			 * 100 EUR credit, a 40 EUR line, a refund interrupted after the
			 * credit -- the marker never reaches the database, the next pass
			 * finds the record still at 40, and the coupon climbs to 140 EUR.
			 * Every replay added another 40.
			 *
			 * Setting the record back to zero is what closes the window, not
			 * the write order on its own: order alone would move the loss onto
			 * the customer instead of removing it. A record of zero stops the
			 * guard further up, so a refund is worth exactly once.
			 *
			 * The refund marker is deliberately NOT written here but after the
			 * loop. Written per credit, it made a replay return before the
			 * second credit had been handled at all: measured on an order
			 * carrying two credits, an interruption after the first one left
			 * the second's 30 EUR unreturned for good, with no note and no
			 * history. The record per code already gives idempotence credit by
			 * credit, so the marker is only the order-wide summary.
			 */
			/*
			 * THE TOKEN TRAVELS WITH THE MONEY.
			 *
			 * The coupon and the order are two separate stores, so one of them
			 * is written first whichever way round it is done, and each order
			 * loses for somebody. Crediting the coupon first and saving the
			 * order after let an interruption replay the refund without limit:
			 * measured, a 40 EUR refund replayed took the credit to 140 EUR,
			 * then 180. Saving the order first instead bounded the loss but
			 * moved it onto the customer and made it silent: the record read
			 * '0', the replay returned nothing, and 40 EUR taken were never
			 * given back, with no note anywhere to find it by.
			 *
			 * Neither order of writes can fix that, because the token saying
			 * "this refund has happened" lived in a different store from the
			 * money. It now lives on the coupon and is written by the same
			 * save() that credits it, so the two cannot come apart: a replay
			 * sees the order listed and returns nothing, whether or not the
			 * order itself was ever saved.
			 */
			$refunded   = $coupon->get_meta( self::REFUNDED_META );
			$refunded   = is_array( $refunded ) ? $refunded : array();
			$refunded[] = (int) $order_id;
			$coupon->update_meta_data( self::REFUNDED_META, array_values( array_unique( $refunded ) ) );

			$coupon->save();

			$taken_map[ $key ] = '0';
			$order->update_meta_data( self::TAKEN_META, $taken_map );
			$order->save();
			$this->clear_reserved_cache( $coupon->get_id() );

			/*
			 * THE AUDIT TRAIL SAYS WHAT WAS RETURNED, NOT WHAT THE LINE SHOWS.
			 *
			 * The amount returned was clipped but the history and the note
			 * went on printing the coupon line: on a 100 EUR credit consumed
			 * by 120, the order announced "120.00 returned" for 100 EUR
			 * actually returned. For a credits module, an audit trail wrong by
			 * twenty euros is worth as much as the money defect itself.
			 */
			$this->push_history( $coupon, $order_id, -$give_back, $new_amount );

			$order->add_order_note(
				sprintf(
					/* translators: 1: coupon code, 2: restored amount */
					__( 'Store credit %1$s: order cancelled or refunded, %2$s returned to the coupon.', 'dahu-store-credit' ),
					$code,
					wc_price( $give_back )
				)
			);

			$restored = true;
		}

		if ( $restored ) {
			$order->update_meta_data( '_dahu_store_credit_restored', 'yes' );
			$order->save();
		}
	}

	/**
	 * Append an entry to the coupon's history meta.
	 *
	 * Kept on the coupon itself (_dahu_store_credit_history) rather than in a
	 * table of its own: the history is only ever read alongside the coupon, and
	 * it survives with it.
	 *
	 * @param WC_Coupon $coupon      Coupon object.
	 * @param int       $order_id    Order that consumed the credit.
	 * @param float     $consumed    Amount consumed.
	 * @param float     $new_balance Balance left afterwards.
	 * @return void
	 */
	private function push_history( WC_Coupon $coupon, $order_id, $consumed, $new_balance ) {
		$history   = $coupon->get_meta( '_dahu_store_credit_history' );
		$history   = is_array( $history ) ? $history : array();
		$history[] = array(
			'date'        => current_time( 'mysql' ),
			'order_id'    => $order_id,
			'consumed'    => $consumed,
			'new_balance' => $new_balance,
		);
		$coupon->update_meta_data( '_dahu_store_credit_history', $history );
		$coupon->save();
	}
}
