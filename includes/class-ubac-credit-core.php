<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The business logic: marking coupons, carrying the balance forward, restoring it.
 */
class Ubac_Credit_Core {

	/**
	 * Order meta holding what each credit actually gave to this order, as an
	 * array keyed by coupon code. A single fixed key, so uninstalling finds
	 * it without a prefix search.
	 */
	const TAKEN_META = '_ubac_credit_taken';

	/**
	 * Coupon meta listing the orders whose refund has already been credited to
	 * this credit. It lives on the COUPON, not on the order, so that it is
	 * written by the very save() that moves the money: one object, one write,
	 * nothing in between. An order-side marker could not do that -- see
	 * restore_order().
	 */
	const REFUNDED_META = '_ubac_credit_refunded_orders';

	/**
	 * What each order took from THIS credit, kept on the credit itself.
	 *
	 * ## Why this exists, and why it is not on the order
	 *
	 * The credit and the order are two datastores that save separately, so one
	 * write lands before the other and an interruption between them loses
	 * something whichever way round they go. Writing the record first — the
	 * order before the credit — bounded the loss but moved it onto the shop,
	 * and measured it at **140 EUR on a 100 EUR credit**: the record said
	 * "took 40", the credit still held its 100, the replay skipped the credit
	 * on the strength of that record, and the cancellation then gave back
	 * 40 EUR that had never been taken.
	 *
	 * That paragraph is history, not a description of this code: with this
	 * meta in place a replay on that state reads the register, finds nothing,
	 * and charges the credit as it should -- measured at a 0,00 EUR gap over
	 * every cut rank. It is written out because an adverse review read it as
	 * an open hole, and prose that reads as a live defect costs a reviewer a
	 * day.
	 *
	 * No ordering fixes that. What fixes it is putting the record **in the
	 * same datastore as the money**, so the two cannot come apart: this meta is
	 * written by the very `save()` that writes the new amount. That is not a
	 * new idea here — it is what closed the refund side, where `REFUNDED_META`
	 * lives on the credit and is written by the save that credits it, measured
	 * at 34 cut ranks out of 34 with a 0.00 EUR gap.
	 *
	 * An array keyed by order id, holding the amount taken as a string.
	 *
	 * ## What becomes of the record on the order
	 *
	 * `TAKEN_META` stays and becomes an **audit copy**: a shop opens an order,
	 * not a coupon, and that is where it needs to read what was taken. It is no
	 * longer the source of truth.
	 *
	 * ## Orders charged before this version
	 *
	 * They have a record on the order and none here. Both the charge guard and
	 * the refund therefore fall back to `TAKEN_META` when this meta holds
	 * nothing for the order in hand, which is exactly the behaviour those
	 * orders had before. New orders get the stronger guarantee, old ones keep
	 * the one they were charged under, and nothing has to be migrated.
	 */
	const TAKEN_BY_META = '_ubac_credit_taken_by_orders';

	/**
	 * WHICH order, and not merely which id, each register entry is about.
	 *
	 * `TAKEN_BY_META` is keyed by order id, and an id can come back: an
	 * import, a migration that renumbers, a partial dump restored. The entry
	 * alone then cannot say whether it is about this order or about a
	 * renumbered homonym -- and the two want opposite answers, so no reading
	 * of a one-state record can be right for both. Measured both ways: read
	 * as "charged", a fresh homonymous order goes through unpaid; read as
	 * "not charged", an order whose own metas were lost is charged a second
	 * time and only half of it ever comes back.
	 *
	 * So the register says which order too. `wc_get_order_key()` is set once
	 * at creation and survives renumbering, which is exactly the property
	 * the id lacks. It goes out in the SAME `$coupon->save()` as the amount
	 * map and the balance, so the three cannot come apart.
	 *
	 * A missing key on either side is not a mismatch: an order can legitimately
	 * have none, and entries written before this map existed have none either.
	 * Only two keys that are both present and different mean "another order".
	 */
	const TAKEN_KEYS_META = '_ubac_credit_taken_by_order_keys';

	/**
	 * WHAT HAS ALREADY BEEN GIVEN BACK, per order, kept on the credit.
	 *
	 * A partial refund and a full one both put money back, and until this
	 * record existed they could not see each other: refunding 60,00 EUR of
	 * cash and then setting the order to refunded would have returned the
	 * credit twice over. One rule now governs both paths -- never give back
	 * more than was taken, and remember what has been given -- and it is
	 * written by the same `save()` as the balance, like every other record
	 * this plugin trusts.
	 *
	 * Cleared when the order is charged again, in the same save as the charge:
	 * a new charge is a new debt, and a record describing the previous cycle
	 * would make the next refund conclude that nothing was owed. The history
	 * on the credit is the audit trail; this record is only the running total
	 * of one cycle.
	 */
	const GIVEN_BACK_META = '_ubac_credit_given_back';

	/**
	 * The codes, on this order, whose register lives on the credit.
	 *
	 * ## Why a second meta rather than a flag on the order
	 *
	 * Without it, the fallback to `TAKEN_META` reopens the very window
	 * `TAKEN_BY_META` closes. Walk it through: the order's audit copy is
	 * written by `$order->save()`, the money and the register by
	 * `$coupon->save()` just after. Cut between the two and the audit copy
	 * says "took 40" while the credit holds its 100 and knows nothing. A guard
	 * that falls back to the order's copy whenever the credit's register is
	 * silent would skip the credit again — 140 EUR, exactly as before.
	 *
	 * So the fallback has to distinguish "charged by a version that kept the
	 * record on the order" from "charged by this version, and the credit's
	 * save was lost". This list is what distinguishes them, and it is written
	 * by the same `$order->save()` as the audit copy: lose that save and
	 * neither exists, so the credit is charged — which is right, since no
	 * money moved.
	 *
	 * ## And why per CODE rather than per order
	 *
	 * An order can carry several credits and be charged across two versions:
	 * the old one charges A, the request dies before B, the plugin is updated,
	 * the replay charges B. A flag on the order would then declare the credit
	 * register authoritative for A as well — and A, absent from any register,
	 * would be charged a SECOND time. Per code, A keeps the rule it was
	 * charged under for ever.
	 *
	 * ## And why it holds the credit's ID, not just the code
	 *
	 * Because a register that lives on the credit dies with the credit. A shop
	 * that deletes a credit and recreates it under the same code gives it a
	 * fresh id and an empty register — and that state is indistinguishable,
	 * from the code alone, from "this version charged it and the credit's save
	 * was lost". The two want opposite answers: the recreated credit was
	 * really charged, so a refund owes the money back, while the lost save
	 * took nothing and owes nothing.
	 *
	 * Measured, before this map held the id: a 100 EUR credit on an order
	 * consuming 120, deleted, recreated under the same code and refunded, came
	 * back as **120 EUR** — the very defect that filing the record under the
	 * code had closed.
	 *
	 * So: `array( code => coupon id )`. Same id and a silent register means
	 * nothing was taken. A different id means the credit was replaced, and the
	 * order's audit copy — filed by code, and surviving the replacement — is
	 * what counts.
	 */
	const REGISTER_CODES_META = '_ubac_credit_register_codes';

	/**
	 * Order meta listing the one-off messages already written on this order, so
	 * a hook that fires again does not repeat them. Measured before it existed:
	 * five passes of a cancelled order wrote the same refusal note five times.
	 */
	const SAID_META = '_ubac_credit_said';


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

		/*
		 * A failed order gives the credit back, exactly like a cancelled one.
		 *
		 * `failed` is reached two ways and both are handled by the same
		 * routine: a payment that never went through, where nothing was
		 * charged and the per-credit guard returns without touching anything;
		 * and an order that WAS charged and later failed, where the money is
		 * owed back. The order stays chargeable afterwards, which is what the
		 * cycle work made safe.
		 */
		add_action( 'woocommerce_order_status_failed', array( $this, 'restore_order' ) );

		/*
		 * And a PARTIAL refund gives back the credit's share of it.
		 *
		 * WooCommerce fires this for every refund, partial or total, with the
		 * refund's own id. The share is worked out in `refund_partially()`;
		 * what matters here is that it goes through the same accounting as a
		 * full refund, so the two cannot pay the same money twice.
		 */
		add_action( 'woocommerce_order_refunded', array( $this, 'refund_partially' ), 10, 2 );

		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'cap_balance_to_available' ), 10, 2 );
		add_filter( 'woocommerce_coupon_error', array( $this, 'customize_coupon_error' ), 10, 3 );
		add_filter( 'woocommerce_coupon_validate_user_usage_limit', array( $this, 'allow_repeat_use' ), 10, 3 );
	}

	private function is_enabled() {
		$settings = get_option( 'ubac_credit_settings', array() );
		$enabled  = isset( $settings['enabled'] ) ? $settings['enabled'] : 'yes';
		return 'yes' === $enabled;
	}

	private function extension_days() {
		$settings = get_option( 'ubac_credit_settings', array() );
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
				'id'          => '_ubac_credit',
				'label'       => __( 'Store credit (balance carries forward)', 'ubac-credit' ),
				'description' => __( 'When ticked, whatever is left of this fixed-cart coupon is carried forward after payment instead of being lost.', 'ubac-credit' ),
				'desc_tip'    => true,
			)
		);
		echo '</div>';

		if ( $coupon_id && 'yes' === $coupon->get_meta( '_ubac_credit' ) && 'fixed_cart' !== $coupon->get_discount_type() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This coupon is marked as store credit but is not of the “Fixed cart discount” type. Ubac Store Credit will ignore it.', 'ubac-credit' ) . '</p></div>';
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

		$value = isset( $_POST['_ubac_credit'] ) ? 'yes' : 'no';

		$coupon->update_meta_data( '_ubac_credit', $value );
		$coupon->save();
	}

	/**
	 * Is this coupon handled by Ubac Store Credit?
	 *
	 * The meta is read straight from the database, bypassing the object cache:
	 * a WC_Coupon left stale after a recent save would otherwise produce a
	 * false negative, and a false negative here means a store credit silently
	 * treated as an ordinary coupon.
	 *
	 * @param WC_Coupon $coupon Coupon object.
	 * @return bool
	 */
	private function is_ubac_credit_coupon( WC_Coupon $coupon ) {
		$meta = $coupon->get_meta( '_ubac_credit' );
		if ( '' === $meta || null === $meta ) {
			$meta = get_post_meta( $coupon->get_id(), '_ubac_credit', true );
		}
		return 'fixed_cart' === $coupon->get_discount_type() && 'yes' === $meta;
	}

	private function log( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->debug( $message, array( 'source' => 'ubac-credit' ) );
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
		$cache_key = 'ubac_credit_reserved_' . $coupon->get_id();
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no API lists the orders carrying a coupon; the computed result is cached in a 30-second transient (read above, written at the end of this method), and the table name comes from $wpdb->prefix.
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
			/*
			 * ALREADY CHARGED? ASK THE CREDIT'S REGISTER, NOT THE FLAG.
			 *
			 * The flag is written AFTER the loop that moves the money, so
			 * between the two this order reads as "not charged" while the
			 * credit has already been debited for it -- and its line was then
			 * counted as reserved on top, taking the amount off the balance a
			 * second time. The customer sees less credit than they hold.
			 * Found by an adverse review; the window is narrow but it is on
			 * the path every charge takes.
			 *
			 * The register is written by the save that moves the money, so it
			 * cannot lag behind it. The flag is kept as a fallback for orders
			 * charged by a version that had no register.
			 */
			/*
			 * The same reading as the charge, through the same method, which
			 * is the point: this used to compare the order id alone, so an
			 * entry left behind by a vanished order excluded a pending
			 * namesake from the reserved amount -- 0,00 EUR instead of
			 * 30,00 EUR -- while the charge side held that same order to be
			 * chargeable. Two readers of one record, disagreeing.
			 */
			$verdict = $this->register_verdict( $coupon, $order, $order_id, $code );

			/*
			 * ONLY `speaks` LEAVES THE LOOP, AND THAT IS AN ARBITRATION.
			 *
			 * DO NOT "RESTORE" `|| 'unmatched' === $verdict` HERE. An earlier
			 * version of this comment described exactly that, with figures,
			 * and the code had meanwhile stopped doing it -- so the comment
			 * read as a fix that had been lost, and the one line it invited
			 * would reverse a weighed decision in the irrecoverable
			 * direction. An adverse review reproduced the figures from the
			 * stale text and had to go and check the bench to find out which
			 * of the two was right. Prose that contradicts the code is the
			 * most expensive kind of defect in this file.
			 *
			 * What is true: on `speaks` the charge is recorded for THIS
			 * order, so the amount has already left the balance and counting
			 * the line again would take it off twice.
			 *
			 * On `unmatched` the record cannot be tied to this order. The
			 * charge side believes it and will not debit, so nothing more
			 * will be taken -- and the line is nevertheless counted as
			 * reserved, ON PURPOSE. The two harms were measured and weighed:
			 *
			 *   not counting it -- 60,00 EUR of goods leave against a credit
			 *   the charge refuses to debit. Shop's loss, irrecoverable.
			 *
			 *   counting it -- a credit really holding 60,00 EUR offers
			 *   20,00 EUR, and with another pending order it is refused at
			 *   the basket. Customer's loss, which an administrator can
			 *   release.
			 *
			 * The recoverable harm was chosen, and the order carries a note
			 * saying why. The decision is pinned by a named assertion in
			 * `_harness/prove-store-credit-register.php`, section 9 bis, on
			 * an uncorroborated line being reserved rather than given away:
			 * changing this line turns that assertion red, which is the
			 * intended way to find out.
			 *
			 * `other-order` is counted too, and for a plainer reason: there
			 * the line belongs to a genuinely new order that nothing has
			 * charged, so it really is reserved.
			 */
			if ( 'speaks' === $verdict ) {
				continue;
			}

			if ( 'yes' === $order->get_meta( '_ubac_credit_processed' ) ) {
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
		delete_transient( 'ubac_credit_reserved_' . $coupon_id );
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

		if ( ! $this->is_ubac_credit_coupon( $coupon ) ) {
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
		if ( 100 === $err_code && $coupon instanceof WC_Coupon && $this->is_ubac_credit_coupon( $coupon ) ) {
			return __( 'The balance of this store credit is currently too low, or already reserved by another order awaiting payment.', 'ubac-credit' );
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
			$this->log( 'Ubac Store Credit is switched off in the settings; nothing processed.' );
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			$this->log( "Order #{$order_id} not found by wc_get_order()." );
			return;
		}

		/*
		 * `_ubac_credit_processed` IS NO LONGER A GATE.
		 *
		 * It was, and that is what made the fix "a record of zero leaves the
		 * credit chargeable again" useless: the gate returned before the loop
		 * ever read the record. Measured on a cycle with no interruption at
		 * all -- charged, cancelled, then back to processing, which
		 * WooCommerce allows: the credit stayed whole at 100 EUR while 40 EUR
		 * of goods went out against it. And an order already in the terminal
		 * state of both flags, set by an earlier version, stayed blocked for
		 * ever -- the fix would have repaired the future only.
		 *
		 * What stops a second charge is no longer an order-wide flag but, one
		 * credit at a time, the register the credit itself carries
		 * (`TAKEN_BY_META`) -- and, for credits charged by an earlier version,
		 * the record on the order, exactly as before. The guard therefore
		 * sits INSIDE the loop, where it knows which credit it is talking
		 * about, and the flag goes back to being a summary.
		 *
		 * What it costs: the loop is walked on an order already processed, to
		 * take nothing from it. That is a handful of reads, and the notes do
		 * not repeat themselves -- `say_once()` is there for that.
		 */

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
			 * Built here rather than further down, because the guard below now
			 * reads a record that lives ON THE CREDIT. Building it is a read
			 * with no side effect; `null` when the code no longer resolves, so
			 * the note about a missing coupon still comes from exactly where it
			 * did before, in the same order.
			 */
			$coupon = $coupon_id ? new WC_Coupon( $coupon_id ) : null;

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
			 * order. A record of zero means a refund has given that credit
			 * back, which leaves it chargeable again.
			 *
			 * EXCEPT THAT THIS IS ALMOST NEVER REACHED, and saying so is worth
			 * more than the sentence above. Nothing clears
			 * `_ubac_credit_processed`: a refund sets its own marker and
			 * leaves that one at 'yes', so the guard at the top of this method
			 * returns before the loop ever reads the zero. Measured on a plain
			 * cycle with no interruption at all -- charge, cancel, then back to
			 * processing, which WooCommerce allows -- the credit stays whole at
			 * 100 EUR while 40 EUR of goods go out against it.
			 *
			 * The only path that does read the zero is the one where an
			 * interruption took the after-loop marker with it. Making the
			 * sentence above true in the ordinary cycle means the refund must
			 * clear `_ubac_credit_processed` in the same save that sets
			 * its own marker, and that is the pending decision about those two
			 * flags -- not something to improvise in this guard.
			 */
			/*
			 * READ THE RECORD THE WAY THE REFUND READS IT.
			 *
			 * This guard used to compare the record to the string '0', which
			 * made it answer a question about type rather than about money.
			 * Measured on a forged record after a normal charge: an integer `0`
			 * or a float `0.0` -- which an export, a re-import or a hand edit
			 * can leave behind, though a charge never writes them -- let the
			 * credit be charged a SECOND time, taking it to 20 EUR instead of
			 * 60 on a 100 EUR credit. And in the other direction an empty
			 * string, a null, a false or an array all counted as "already
			 * charged", so a real charge was skipped and the goods went out
			 * free.
			 *
			 * So a record is read as a number, and compared to zero as a
			 * number. Anything that is not a readable number is treated here as
			 * "already charged", because charging twice takes money from the
			 * customer and needs a refund to undo.
			 *
			 * WHAT THE PAIR COSTS, MEASURED. The refund side makes the opposite
			 * choice on the same unreadable record -- it treats it as missing
			 * and falls back on the coupon line. Read separately each side is
			 * defensible; read together they COMPOUND. On a 100 EUR credit with
			 * a 40 EUR line whose record was already damaged before the charge:
			 * nothing is taken, the goods go out, and the refund then hands
			 * back the whole line, leaving the credit at 140 EUR. That is
			 * 80 EUR of exposure on a 40 EUR line, not the 40 EUR a single side
			 * suggests.
			 *
			 * Neither side can be corrected alone, and that is the real finding
			 * rather than an oversight: a one-state record cannot say both
			 * "the money was taken" and "it was not", and the bench holds both
			 * readings because both occur. Closing it means the two-state
			 * record -- intended, then confirmed -- named further down in
			 * process_order(). Until that exists this is a sum of two risks,
			 * not a choice between them.
			 */
			/*
			 * THE CREDIT'S OWN REGISTER FIRST. See `TAKEN_BY_META`.
			 *
			 * It is the only record that cannot have been written without the
			 * money having moved, because one `save()` writes both. The order's
			 * copy is consulted only when this one says nothing about this
			 * order — an order charged by an earlier version — and it then
			 * behaves exactly as it did then.
			 */
			/*
			 * THE KEY SAYS WHICH REFUSAL, NOT JUST "A REFUSAL".
			 *
			 * The four notes below are mutually exclusive on one pass, so a
			 * single key looked harmless. Across two passes it is not: the
			 * first pass writes "already recorded as charged", the credit is
			 * then deleted and recreated under the same code, and the note
			 * that matters -- "it is not the one that paid for this order" --
			 * is swallowed because something was already said under that key.
			 * Each refusal therefore carries its own key.
			 */
			$already = wc_format_coupon_code( $code );

			list( $seen, $replaced, $unmatched ) = $this->charge_record( $coupon, $coupon_id, $order, $order_id, $code );

			if ( null !== $seen ) {

				if ( ! is_numeric( $seen ) || ! is_finite( (float) $seen ) ) {
					$this->log( "Order #{$order_id} carries an unreadable charge record for credit {$code}; skipping it." );
					$this->say_once( $order, 'skipped-unreadable-' . $already, sprintf(
						/* translators: %s: coupon code */
						__( 'Store credit %s: this order carries a charge record that cannot be read, so the credit was left alone. Check the credit before treating this order as paid by it.', 'ubac-credit' ),
						$code
					) );
					continue;
				}

				if ( 0.0 !== round( (float) $seen, $decimals ) ) {
					$this->log( "Order #{$order_id} already charged credit {$code}; skipping it." );

					/*
					 * THE NOTE SAYS WHAT IS TRUE OF THE CREDIT IN HAND.
					 *
					 * The refund side already told the shop when the credit
					 * answering to the code was not the one that was charged.
					 * This side knew the same thing and threw it away, then
					 * wrote "already charged on that credit" about a credit
					 * that had never lent anything -- a note contradicting the
					 * facts, which is the defect this module refuses
					 * elsewhere. The skip itself is right: the order was paid
					 * by the credit that has since been destroyed.
					 */
					if ( $replaced ) {
						$this->say_once( $order, 'skipped-replaced-' . $already, sprintf(
							/* translators: %s: coupon code */
							__( 'Store credit %s: this order was charged on a credit that has since been deleted and recreated under the same code, so the credit now carrying that code was left alone. It is not the one that paid for this order.', 'ubac-credit' ),
							$code
						) );
						continue;
					}

					if ( $unmatched ) {
						$this->say_once( $order, 'skipped-unmatched-' . $already, sprintf(
							/* translators: %s: coupon code */
							__( 'Store credit %s: a charge is recorded against this order id, but it could not be matched to this order, so the credit was left alone. Check whether this order was really paid by that credit.', 'ubac-credit' ),
							$code
						) );
						continue;
					}

					$this->say_once( $order, 'skipped-' . $already, sprintf(
						/* translators: %s: coupon code */
						__( 'Store credit %s: this order is already recorded as charged on that credit, so it was not charged again.', 'ubac-credit' ),
						$code
					) );
					continue;
				}
			}

			if ( ! $coupon_id ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: coupon code */
						__( 'Ubac Store Credit: coupon “%s” no longer exists; nothing was processed.', 'ubac-credit' ),
						$code
					)
				);
				continue;
			}

			// Built further up, for the register guard.
			if ( ! $this->is_ubac_credit_coupon( $coupon ) ) {
				// An ordinary coupon is the normal case, not a problem: a shop
				// using plain coupons alongside store credit would otherwise
				// get a note on every order. It also used to print the raw
				// meta value onto the order screen, which is debugging output,
				// not something a merchant should read.
				$this->log(
					sprintf(
						'Order #%1$d: coupon "%2$s" is not store credit (type=%3$s, _ubac_credit=%4$s); left alone.',
						$order_id,
						$code,
						$coupon->get_discount_type(),
						$coupon->get_meta( '_ubac_credit' ) ? $coupon->get_meta( '_ubac_credit' ) : '(empty)'
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
			$taken = round( $amount - $remaining, $decimals );

			/*
			 * THE REGISTER ON THE CREDIT, written by the save that debits it.
			 *
			 * `set_amount()` above and this meta go out in the same
			 * `$coupon->save()`: the money and the record of what was taken
			 * can no longer come apart, which is the whole point. It is the
			 * pattern that already closed the refund side.
			 */
			$register = $coupon->get_meta( self::TAKEN_BY_META );
			$register = is_array( $register ) ? $register : array();

			$reg_keys = $coupon->get_meta( self::TAKEN_KEYS_META );
			$reg_keys = is_array( $reg_keys ) ? $reg_keys : array();

			/*
			 * AN OVERWRITTEN CLAIM IS SAID BEFORE IT IS OVERWRITTEN.
			 *
			 * Charging a renumbered namesake replaces the entry the previous
			 * holder of this id left behind, and that entry was a claim on
			 * real money: 40,00 EUR, in the case measured. The replacement is
			 * right -- the old order no longer exists, and the register is
			 * keyed by id -- but losing the only record of that claim without
			 * a word is an audit hole, and this module's whole argument is
			 * that money leaves a record. So the order says what was
			 * displaced; the new entry is then written as usual.
			 */
			$was      = array_key_exists( (string) $order_id, $register ) ? $register[ (string) $order_id ] : null;
			$was_key  = isset( $reg_keys[ (string) $order_id ] ) ? (string) $reg_keys[ (string) $order_id ] : '';
			$this_key = (string) $order->get_order_key();

			// And only for a claim on real money: the comment below justifies
			// this note by what was displaced, so a displaced zero has
			// nothing to say.
			$was_amount = ( null !== $was && is_numeric( $was ) ) ? round( (float) $was, $decimals ) : 0.0;

			if ( null !== $was && $was_amount > 0 && '' !== $was_key && '' !== $this_key && $was_key !== $this_key ) {
				$this->say_once( $order, 'displaced-' . $already, sprintf(
					/* translators: 1: coupon code, 2: formatted amount */
					__( 'Store credit %1$s: this order id already carried a charge of %2$s made by a different order, which no longer exists. That record has been replaced by this one.', 'ubac-credit' ),
					$code,
					wc_price( $was_amount )
				) );
			}

			$register[ (string) $order_id ] = (string) $taken;
			$coupon->update_meta_data( self::TAKEN_BY_META, $register );

			$reg_keys[ (string) $order_id ] = $this_key;
			$coupon->update_meta_data( self::TAKEN_KEYS_META, $reg_keys );

			/*
			 * AND THE ORDER LEAVES THE REFUNDED LIST, in the same save. The
			 * exact mirror of what the refund does to the register.
			 *
			 * Without it an ordinary cycle stopped at the second turn:
			 * charged, refunded, back to completed, charged again -- and the
			 * second refund saw the order already listed as refunded and gave
			 * nothing back. Measured: 79 EUR taken and never returned, the
			 * credit left at 21 EUR instead of 100.
			 *
			 * The two lists are therefore mutually exclusive, and that is the
			 * invariant that counts: for a given credit, an order is either
			 * charged or refunded, never both. Carried by the credit and
			 * written by a single save, they cannot contradict each other.
			 */
			$refunded_before = $coupon->get_meta( self::REFUNDED_META );
			$refunded_before = is_array( $refunded_before ) ? $refunded_before : array();

			$refunded_before = array_values(
				array_filter(
					array_map( 'intval', $refunded_before ),
					function ( $seen ) use ( $order_id ) {
						return (int) $seen !== (int) $order_id;
					}
				)
			);

			$coupon->update_meta_data( self::REFUNDED_META, $refunded_before );

			/*
			 * AND WHAT WAS GIVEN BACK BEFORE IS FORGOTTEN, in the same save.
			 *
			 * A new charge is a new debt. Without this, the record of an
			 * earlier refund survived into the next cycle and the refund that
			 * followed concluded the debt was already settled: the sweep put
			 * the figure on it immediately -- a second refund returning
			 * nothing, at every one of the ninety-six cut ranks. It is the
			 * exact mirror of the line above, and for the same reason: both
			 * records describe the PREVIOUS cycle, and both must go when a new
			 * one begins.
			 */
			$given_before = $coupon->get_meta( self::GIVEN_BACK_META );
			$given_before = is_array( $given_before ) ? $given_before : array();

			unset( $given_before[ (string) $order_id ] );
			$coupon->update_meta_data( self::GIVEN_BACK_META, $given_before );

			/*
			 * And the audit copy on the order, which is no longer the source
			 * of truth: a merchant opens an order, not a coupon.
			 */
			$taken_map      = $order->get_meta( self::TAKEN_META );
			$taken_map      = is_array( $taken_map ) ? $taken_map : array();
			$taken_map[ wc_format_coupon_code( $code ) ] = (string) $taken;
			$order->update_meta_data( self::TAKEN_META, $taken_map );

			// And the switch, for THIS credit and THIS coupon: see
			// `REGISTER_CODES_META`.
			$on_credit = $order->get_meta( self::REGISTER_CODES_META );
			$on_credit = is_array( $on_credit ) ? $on_credit : array();

			$on_credit[ $already ] = (int) $coupon_id;
			$order->update_meta_data( self::REGISTER_CODES_META, $on_credit );

			/*
			 * THE LIST OF USERS IS LEFT ALONE, and that is the measured answer.
			 *
			 * This block used to filter `get_used_by()` and write the result
			 * back as a meta called `used_by`, meaning to let the same
			 * customer spend the rest of their credit. Measured against a real
			 * WooCommerce rather than reasoned about, it did two things and
			 * both were wrong: WooCommerce keeps that list as one `_used_by`
			 * ROW PER USE, with an underscore, so the write left a stray
			 * `used_by` row on the coupon -- and `get_used_by()` went on
			 * returning the customer, so the removal never happened.
			 *
			 * `decrease_usage_count()` was the next guess, and it was measured
			 * too: it moves the counter and does NOT remove a `_used_by` row,
			 * in either order. WooCommerce exposes no API that does, so
			 * shipping a call that cannot work would be an inert control --
			 * the one thing this plugin's harness refuses everywhere else.
			 *
			 * What actually blocks a second use is `usage_limit_per_user`, and
			 * that check has a filter of its own. It is answered where it is
			 * asked: see `allow_repeat_use()`. A credit's limit is its
			 * balance, not a number of uses.
			 *
			 * The stray row is still removed where a previous version wrote it.
			 */
			if ( '' !== (string) $coupon->get_meta( 'used_by' ) ) {
				$coupon->delete_meta_data( 'used_by' );
			}

			/*
			 * EXTENDING IS NOT SETTING, and this side was setting.
			 *
			 * The date used to be posted unconditionally, which quietly
			 * DESTROYED credit: a credit good for a year, spent against a
			 * 40 EUR order with a thirty-day window configured, saw its
			 * remaining 60,00 EUR expire in thirty days instead of three
			 * hundred and sixty-five -- measured at 335 days lost. A credit
			 * with no expiry at all was given one, inventing a date of death
			 * the shop had never asked for.
			 *
			 * Found by an adverse review, and it only became visible because
			 * the refund side had just been taught never to pull a date in:
			 * the asymmetry was the evidence. The setting is named for an
			 * extension, so it extends and never shortens, and it has nothing
			 * to say about a credit that never expires.
			 */
			$extension_days = $this->extension_days();
			$expires        = $coupon->get_date_expires();

			if ( $extension_days > 0 && $remaining > 0 && $expires ) {
				$window = time() + ( $extension_days * DAY_IN_SECONDS );

				if ( $expires->getTimestamp() < $window ) {
					$coupon->set_date_expires( $window );
				}
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
			 *
			 * WHAT THIS COSTS, SAID PLAINLY. Writing the record first does not
			 * close the window, it turns it round, and the shop now pays for
			 * it instead of the customer. An interruption between this save and
			 * the debit below leaves a record saying "taken" on a credit that
			 * was never debited: the replay reads that record and skips the
			 * credit, so 40 EUR of goods go out against a credit still worth
			 * 100, and cancelling the order then returns 40 EUR that were never
			 * taken -- measured at 140 EUR on a 100 EUR credit. The window is
			 * one call wide and the direction was chosen deliberately, because
			 * a customer cannot see a credit quietly drained whereas a shop has
			 * at least a chance of noticing its own. That is a weaker claim
			 * than it first looks: the skip is announced on the order now, but
			 * the refund that follows still says it returned 40 EUR that were
			 * never taken, so the order tells the shop two things that cannot
			 * both be true. It is not fixed, and closing it properly means
			 * either recording the balance before the debit so this guard can
			 * check the record against the credit, or writing the record in two
			 * states, intended then confirmed, with only a confirmed record
			 * counting. Both are real work, and neither should be improvised
			 * here.
			 */
			$order->save();

			$coupon->save();
			$this->clear_reserved_cache( $coupon->get_id() );

			/*
			 * WHAT WAS TAKEN, NOT WHAT THE LINE CONSUMED.
			 *
			 * A charge clips: a 120 EUR line on a 100 EUR credit takes 100.
			 * The history was handed `$consumed`, so it read "consumed 120,
			 * new balance 0" -- wrong by 20 EUR, and no longer summing to the
			 * balance it sits beside. The refund side already forbids exactly
			 * this, in writing, and for the same reason: on a credits module
			 * the audit trail is part of the money.
			 */
			$this->push_history( $coupon, $order_id, $taken, $remaining );

			if ( $remaining > 0 ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: coupon code, 2: consumed amount, 3: remaining balance */
						__( 'Store credit %1$s: %2$s used, %3$s carried forward.', 'ubac-credit' ),
						$code,
						wc_price( $consumed ),
						wc_price( $remaining )
					)
				);
			} else {
				$order->add_order_note(
					sprintf(
						/* translators: %s: coupon code */
						__( 'Store credit %s fully used.', 'ubac-credit' ),
						$code
					)
				);
			}

			$processed = true;
		}

		if ( $processed ) {

			/*
			 * THE TWO FLAGS ARE NEVER SET TOGETHER.
			 *
			 * It was the terminal state that blocked everything: after a
			 * charge and then a refund the order carried `processed = yes`
			 * AND `restored = yes`, and nothing could charge or refund any
			 * more. Measured on an ordinary cycle -- 100 EUR, 79 spent,
			 * refunded, spent again: the customer kept the goods and the
			 * credit.
			 *
			 * What makes it safe to uncross them is `TAKEN_BY_META`: what
			 * stops a second charge is no longer an order-wide flag but the
			 * register the credit itself carries. The two flags go back to
			 * being what they should always have been -- a summary of what the
			 * last pass did -- and a summary cannot say two contradictory
			 * things at once.
			 */
			$order->update_meta_data( '_ubac_credit_processed', 'yes' );
			$order->update_meta_data( '_ubac_credit_restored', '' );
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
		/*
		 * AND BOTH OF ITS CONDITIONS WERE STILL ON THE ORDER. Found by a
		 * later adverse review, which is the same lesson arriving twice.
		 *
		 * The marker and the audit copy are both order metas -- exactly the
		 * datastore this module declares unreliable. An import that drops an
		 * order's metas therefore closed this door while the credit's own
		 * register still said, in plain terms, that 40,00 EUR had been taken:
		 * measured, 40,00 EUR kept from the customer with no note, while
		 * `process_order()` looking at the SAME state correctly believed the
		 * register and refused to charge again. One state, two opposite
		 * readings, and the money fell in the gap.
		 *
		 * There is no cheap order-side question that answers "was anything
		 * taken" -- only the credits know. So nothing is decided here: the
		 * loop is walked and the per-credit resolver decides, which costs one
		 * resolver call per coupon on an order that was never charged and
		 * writes nothing in that case.
		 */

		/*
		 * `_ubac_credit_restored` IS NO LONGER A GATE EITHER, and this
		 * one took money from a customer for good.
		 *
		 * Found by an adverse review and then measured. The flag is cleared
		 * only by the after-loop save, so on a SECOND cycle -- an order
		 * already refunded once, hence `restored = yes` -- a cut on that
		 * after-loop save leaves the credit debited and the register written
		 * while the flag still reads `yes`. This gate then returned, and no
		 * replay could repair it: `process_order()` skips the credit on the
		 * strength of the register, so nothing is charged, the after-loop
		 * save is never reached again, and the flag stays rancid for ever.
		 * Measured at 40,00 EUR missing after five process-then-restore
		 * rounds, on two of the forty-eight cut ranks.
		 *
		 * Same remedy as for `_ubac_credit_processed`, and the same
		 * reason: what prevents a second credit-back is not an order-wide
		 * flag but `REFUNDED_META`, carried by the credit and written by the
		 * save that credits it, one credit at a time. The loop is therefore
		 * walked, the per-credit guard decides, and the flag goes back to
		 * being a summary.
		 *
		 * The bench's own blind spot is worth recording: its sweep built a
		 * virgin credit every time, so a rancid flag could not occur in it. A
		 * sweep that is exhaustive over one starting state is not exhaustive.
		 */

		$decimals     = wc_get_price_decimals();
		/*
		 * WHAT CLAIMS A CHARGE, AND NOT MERELY WHAT THE ORDER STILL SHOWS.
		 *
		 * This loop used to walk the order's coupon LINES. Taking a coupon off
		 * an order in the WooCommerce admin does not zero its line, it deletes
		 * the item -- so the loop did not run once, the register was never
		 * read, and cancelling the order gave nothing back: measured, 40,00
		 * EUR kept from the customer, no note, no history, and the register
		 * still saying 40 where anyone could read it. Replaying the
		 * cancellation repaired nothing. The guard moved into the loop
		 * protects nothing when it is the loop that disappears.
		 *
		 * So the refund walks the union of everything that claims this order
		 * took something: the coupon lines, plus every code named by either
		 * record on the order. Codes are normalised once, here, so a line and
		 * a record differing only in case are one entry and not two.
		 */
		$claims   = $this->claims_on( $order, $decimals );

		/*
		 * A `refunded` STATUS REACHED BY REFUNDING ONLY THE SHIPPING.
		 *
		 * On an order whose goods were paid entirely by credit, the item lines
		 * net to zero, so the ORDER total is nothing but shipping. Refund that
		 * shipping -- the commonest gesture in retail, for a late parcel --
		 * and WooCommerce considers the order fully refunded and flips its
		 * status, which brings this method here to return the whole credit
		 * while the customer keeps the goods. An adverse review measured it at
		 * 100,00 EUR created, and the real WooCommerce confirmed it.
		 *
		 * The status is NOT contradicted by arithmetic: a shop that marks an
		 * order refunded has declared something, and plenty of shops do their
		 * bookkeeping that way without ever creating refund records -- denying
		 * them would keep a customer's money, which is the worse of the two
		 * harms. So the money goes back, and the exposure is SAID, the way
		 * every other arbitration in this plugin is said.
		 */
		$biens  = 0.0;
		$rendus = 0.0;

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$biens  += (float) $item->get_total();
			$rendus += (float) $order->get_total_refunded_for_item( $item_id );
		}

		/*
		 * The predicate is "a refund exists and NO GOODS came back", and it
		 * used to carry a third clause -- that the item lines netted to zero
		 * -- which restricted the warning to orders paid entirely by credit.
		 * An adverse review measured what that left out: goods of 100,00 EUR
		 * with a 40,00 EUR credit, the shipping alone refunded and the order
		 * then marked refunded for the books. 40,00 EUR went back, not a cent
		 * of goods had been returned, the customer kept all of it, and the
		 * order carried only the ordinary "40.00 returned" note.
		 *
		 * Whether the credit paid for all of the goods or some of them
		 * changes nothing about the exposure, so it is no longer asked.
		 */
		$port_seul = ( $order->has_status( 'refunded' )
			&& round( (float) $order->get_total_refunded(), $decimals ) > 0
			&& round( $rendus, $decimals ) <= 0 );
		$restored = false;

		foreach ( $claims as $claim ) {
			$code      = $claim['code'];
			$coupon_id = wc_get_coupon_id_by_code( $code );

			if ( ! $coupon_id ) {

				/*
				 * ONCE, AND ONLY WHERE SOMETHING CLAIMED A CHARGE.
				 *
				 * This was a bare `add_order_note()`, and the door that used
				 * to stand at the top of this method hid what that cost. An
				 * adverse review measured it once the door was gone: five
				 * passes over an order that had never taken anything --
				 * cancelled, refunded, cancelled -- wrote the same line five
				 * times. That is precisely the regression `say_once()` exists
				 * to prevent, arriving again through the new way in.
				 *
				 * And an ordinary coupon that has since been deleted is not
				 * this module's business at all: the note is written only
				 * where a record claims this order took something from a
				 * credit, which is the case a shop would want to know about.
				 */
				if ( ! empty( $claim['recorded'] ) ) {
					$this->say_once( $order, 'notfound-' . wc_format_coupon_code( $code ), sprintf(
						/* translators: %s: coupon code */
						__( 'Ubac Store Credit: could not restore “%s”, coupon not found.', 'ubac-credit' ),
						$code
					) );
				}

				continue;
			}

			$coupon = new WC_Coupon( $coupon_id );

			if ( ! $this->is_ubac_credit_coupon( $coupon ) ) {
				continue;
			}

			/*
			 * THE LINE IS NOT CONSULTED BEFORE THE REGISTER.
			 *
			 * This used to begin with `if ( $consumed <= 0 ) { continue; }`,
			 * and an adverse review measured what that cost: an admin who
			 * recalculates an order and takes the coupon line off it, then
			 * cancels it, left the register saying 40,00 EUR had been taken
			 * and this loop never reading it -- no credit back, no note, no
			 * history line, 40,00 EUR kept from the customer with the record
			 * of the charge still sitting there in plain sight.
			 *
			 * The line is an input to the FALLBACK, for a charge whose amount
			 * nothing records any more. It is not evidence about whether the
			 * money moved; only the register is. So the zero case is now
			 * decided after the record has been read, by `$give_back` below.
			 */
			$consumed = $claim['consumed'];

			/*
			 * This order's refund already credited to this credit? Then there
			 * is nothing to do, and the token that says so sits on the coupon
			 * itself -- see the write below for why it cannot be on the order.
			 *
			 * BUT IT SAYS SO ON THE ORDER. The token is an order id, and an id
			 * can come back: an import, a migration that renumbers, a partial
			 * dump restored, order storage repopulated. A second, unrelated
			 * order carrying the same id then finds itself already refunded and
			 * gets nothing back -- measured at 25 EUR kept from a customer with
			 * nothing anywhere to find it by. The case is rare, because MySQL
			 * does not hand the same id out twice on its own; the silence was
			 * the defect, not the frequency. So the refusal is written on the
			 * order, where a shop looking at that order will see it.
			 */
			$done = $coupon->get_meta( self::REFUNDED_META );
			if ( is_array( $done ) && in_array( (int) $order_id, array_map( 'intval', $done ), true ) ) {
				/*
				 * « NOT RETURNED TWICE », AND NOT « NOTHING WAS RETURNED ».
				 *
				 * The first wording claimed nothing had come back, which is
				 * true for an unrelated order that reused an id -- the case
				 * this token exists for -- but FALSE in the case the token
				 * absorbs every day: a refund whose money was credited and
				 * whose order save was lost. There the credit did come back,
				 * and the note told the shop to go and check a credit that was
				 * already whole. A note that contradicts the facts is the
				 * defect the module elsewhere refuses to tolerate, so the
				 * wording now says only what is true in both cases.
				 */
				$this->say_once( $order, 'refused-' . wc_format_coupon_code( $code ), sprintf(
					/* translators: %s: coupon code */
					__( 'Store credit %s: this order is already recorded as refunded on that credit, so it was not returned twice.', 'ubac-credit' ),
					$code
				) );
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
			$key = wc_format_coupon_code( $code );

			/*
			 * THE CREDIT'S REGISTER IS AUTHORITATIVE WHEREVER IT SPEAKS, and
			 * the order's copy answers only for its silence. The whole of that
			 * reasoning, and the two measured defects that forced it, are in
			 * `charge_record()`.
			 */
			list( $recorded, $replaced, $unmatched ) = $this->charge_record( $coupon, $coupon_id, $order, $order_id, $code );

			/*
			 * A CHARGE THAT CANNOT BE TIED TO THIS ORDER IS NOT PAID OUT HERE.
			 *
			 * The charge side believes such a record, because the alternative
			 * is charging a customer twice. This side must not: paying it out
			 * would hand over an amount that another order took --
			 * measured, a renumbered namesake with no order key took the
			 * credit from 60,00 EUR back to 100,00 EUR while 80,00 EUR of
			 * goods had gone. The two callers want opposite answers from this
			 * one state, and that is not an inconsistency: belief costs the
			 * shop its goods, payment creates money.
			 *
			 * So nothing moves and the order says so, because the shop holds
			 * the one piece of information this code does not -- whether this
			 * really is the order that was charged. Where the record claims
			 * nothing was taken there is nothing to refuse and nothing to say.
			 */
			if ( $unmatched ) {

				$claimed = ( is_numeric( $recorded ) && is_finite( (float) $recorded ) )
					? round( (float) $recorded, $decimals )
					: null;

				if ( null !== $claimed && $claimed <= 0 ) {
					continue;
				}

				$this->say_once( $order, 'unmatched-' . $key, sprintf(
					/* translators: %s: coupon code */
					__( 'Store credit %s: a charge is recorded against this order id but cannot be tied to this order, so nothing was returned automatically — returning it could hand over an amount another order took. Check whether this order was paid by that credit and refund it by hand if so.', 'ubac-credit' ),
					$code
				) );
				continue;
			}

			/*
			 * ABSENT IS NOT UNREADABLE, and conflating the two created money.
			 *
			 * Found by an adverse review, and measured four ways. The
			 * fallback to the coupon line is there for a record this plugin
			 * cannot READ -- a meta re-encoded by an import, a failed
			 * serialization -- where over-paying and saying so beats a silent
			 * refund. It was also reached when NOTHING claimed a charge at
			 * all, and then it paid the line for a credit that had never been
			 * touched:
			 *
			 *   - a charge cut between the register and the debit, and the
			 *     shop simply cancels the order without a replay: 140 EUR on
			 *     a 100 EUR credit, 220 EUR on a 120 EUR line;
			 *   - an order carrying three credits, cut on the first debit:
			 *     130 / 140 / 150 EUR, of which 90 EUR on two credits the
			 *     loop had never even reached;
			 *   - and with NO interruption at all: an order carrying a second
			 *     coupon that was not store credit when the charge ran and
			 *     became so afterwards -- 100 EUR to 145 EUR on a 45 EUR line.
			 *
			 * The charge side already reads this silence correctly: no entry
			 * means never charged. The refund now reads it the same way, and
			 * the asymmetry was the whole defect.
			 *
			 * `null` is therefore "nothing claims a charge for this credit on
			 * this order" -- nothing was taken, nothing is owed. A key that
			 * EXISTS with a value that cannot be read is a different thing:
			 * the order does claim a charge, the amount is lost, and the
			 * documented over-pay with a note applies.
			 */
			if ( null === $recorded ) {
				continue;
			}

			$taken = is_numeric( $recorded ) && is_finite( (float) $recorded )
				? round( (float) $recorded, $decimals )
				: $consumed;

			/*
			 * WHAT IS OWED IS WHAT WAS TAKEN, LESS WHAT IS ALREADY BACK.
			 *
			 * A partial refund can have returned part of this charge already.
			 * Both paths read and write the same record, so the credit is
			 * never given twice: refunding the cash in instalments and then
			 * setting the order to refunded used to be two independent
			 * routines, and would have paid the whole charge over again.
			 */
			$given     = $coupon->get_meta( self::GIVEN_BACK_META );
			$given     = is_array( $given ) ? $given : array();
			$deja      = $this->already_given( $given, $order_id, $taken, $decimals );
			$give_back = round( $taken - $deja, $decimals );

			if ( $this->given_damaged( $given, $order_id ) ) {
				$this->say_once( $order, 'given-damaged-' . $key, sprintf(
					/* translators: %s: coupon code */
					__( 'Store credit %s: the record of what had already been returned on this order could not be read, so it was treated as nothing returned yet. Check that credit\'s balance and its history.', 'ubac-credit' ),
					$code
				) );
			}


			/*
			 * NOTHING TO RETURN, NOTHING TO SAY.
			 *
			 * A credit already empty when the order was charged gave nothing,
			 * yet the coupon line still carries its amount. The guard further
			 * up looks at that line rather than at the charge itself, and so
			 * allowed a note saying "50.00 returned", a history entry of
			 * -50 EUR and the refund marker, for nothing returned at all.
			 */
			/*
			 * ALREADY SETTLED BY INSTALMENTS, AND THE ORDER MUST STILL BE FREED.
			 *
			 * Where partial refunds have given back the whole charge, nothing
			 * is owed -- but the register still says this order holds a
			 * charge, and leaving it there would make the order neither
			 * refundable nor chargeable: re-completing it would send the goods
			 * out without taking a cent, because the charge side believes the
			 * register. So the entry is cleared and the order marked, with no
			 * note about returning anything, because nothing was returned
			 * here. This case did not exist before partial refunds did.
			 */
			/*
			 * NO "ALREADY SETTLED" BRANCH HERE, and that is measured.
			 *
			 * One was written: where partial refunds had returned the whole
			 * charge, this method still had to free the order on the credit,
			 * or re-completing it would send the goods out for nothing. Then
			 * the partial path was given the same rule -- an order is freed
			 * exactly when the whole of its charge is back -- and it frees the
			 * order itself, marking it in `REFUNDED_META`. The guard at the
			 * top of this loop therefore answers first, and says something
			 * true: this order is already recorded as refunded on that credit,
			 * so nothing was returned twice.
			 *
			 * The branch became unreachable, and unreachable code is worse
			 * than none: nothing can prove it still works. It is gone, and
			 * this comment is what remains of it.
			 */
			if ( $give_back <= 0 ) {

				/*
				 * UNLESS SOMETHING CLAIMED A CHARGE AND WE CANNOT READ IT.
				 *
				 * The fallback for an unreadable record is the coupon line,
				 * and a claim that came from a record rather than from a line
				 * has no line: the fallback is then zero and the loop went
				 * round in silence. Measured by an adverse review: an order
				 * charged by an earlier version, its record unreadable and
				 * its coupon line removed in the admin, got nothing back and
				 * was told nothing -- the silent refund this module refuses
				 * everywhere else. A readable record of zero is different:
				 * nothing was taken, so there is nothing to say.
				 */
				if ( ! is_numeric( $recorded ) || ! is_finite( (float) $recorded ) ) {
					$this->say_once( $order, 'unreadable-' . $key, sprintf(
						/* translators: %s: coupon code */
						__( 'Store credit %s: this order claims a charge on that credit but the amount recorded cannot be read, and the order no longer carries the coupon line to fall back on, so nothing was returned. Check the credit and refund it by hand if something is owed.', 'ubac-credit' ),
						$code
					) );
				}

				continue;
			}

			/*
			 * THE CREDIT WAS REPLACED SINCE THE CHARGE, AND THE SHOP IS TOLD
			 * SO -- here, where the money has actually moved.
			 *
			 * This note used to be written as soon as the replacement was
			 * noticed, which was before the two guards above. An adverse
			 * review measured the result: on a credit recreated after the
			 * refund had already run, the order was told that "what was taken
			 * has been returned to the new one" when 0,00 EUR had moved. A
			 * note that contradicts the facts is the defect this module
			 * refuses elsewhere, so the note now sits after the decision it
			 * describes.
			 *
			 * The refund still goes through, clipped to what was taken,
			 * because the customer's refund is owed whatever the shop did
			 * with its own records. But the sum can then exceed what the
			 * credit ever lent -- measured at 140,00 EUR on a 100,00 EUR
			 * credit with a 40,00 EUR line, and 200,00 EUR with a 120,00 EUR
			 * line. The same figures come out of the version before this
			 * register existed, so this is not new; what is new is that it no
			 * longer happens silently.
			 */
			$new_amount = round( (float) $coupon->get_amount() + $give_back, $decimals );
			$coupon->set_amount( $new_amount );
			$coupon->set_usage_count( 0 );

			/*
			 * A RIGHT BALANCE ON A DEAD CREDIT IS NOT THE MONEY BACK.
			 *
			 * Found by an adverse review. The charge pushes the expiry out,
			 * but only while something is left to spend: a credit consumed to
			 * the last cent keeps the date it had. Cancel that order and the
			 * money came back onto a credit that had meanwhile expired --
			 * measured, 100,00 EUR returned and not a cent of it spendable.
			 * The balance was exact and the customer was still out the money.
			 *
			 * So the refund pushes the date out the same way the charge does,
			 * and never pulls it in: a credit already good for longer than the
			 * configured window keeps its own date.
			 */
			$extension_days = $this->extension_days();
			$expires        = $coupon->get_date_expires();
			$say_expired    = false;

			if ( $extension_days > 0 && $new_amount > 0 ) {
				$window = time() + ( $extension_days * DAY_IN_SECONDS );
				if ( $expires && $expires->getTimestamp() < $window ) {
					$coupon->set_date_expires( $window );
				}
			} elseif ( $expires && $expires->getTimestamp() < time() && $new_amount > 0 ) {
				/*
				 * No window is configured, so there is no date this module may
				 * invent -- the shop chose not to manage expiry here. What it
				 * must not do is return money in silence onto a credit that
				 * cannot be used, so it says so and leaves the decision where
				 * it belongs.
				 */
				$say_expired = true;
			}

			/* The same as at the charge, and for the measured reasons given there. */
			if ( '' !== (string) $coupon->get_meta( 'used_by' ) ) {
				$coupon->delete_meta_data( 'used_by' );
			}

			/*
			 * THE RECORD IS SPENT AFTER THE CREDIT, AND ON PURPOSE.
			 *
			 * The title here used to claim the opposite -- that the record was
			 * spent first, as on the charge side -- and the code has never done
			 * that: the credit is written by the save above, and the record is
			 * zeroed by the save below. The two sides of this cycle are NOT
			 * mirror images, and pretending otherwise is the kind of gap that
			 * costs a reader an hour.
			 *
			 * The reason the order is this way round is the token. A refund
			 * used to credit the coupon and then save its own order, which
			 * repeated without limit: a 100 EUR credit, a 40 EUR line, a refund
			 * interrupted after the credit, and the coupon climbed to 140 EUR,
			 * then 180. Reversing the two writes only moved the loss onto the
			 * customer. What closes the window is that the token saying "this
			 * refund has happened" is written by the very save that moves the
			 * money -- see below -- so the record can safely be zeroed after,
			 * and a lost order save costs nothing.
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

			/*
			 * And what is now back, in the same save as the balance. A partial
			 * refund reads this before giving anything, so the two paths can
			 * never pay the same charge twice.
			 */
			$given = $coupon->get_meta( self::GIVEN_BACK_META );
			$given = is_array( $given ) ? $given : array();

			$given[ (string) $order_id ] = (string) round( $deja + $give_back, $decimals );
			$coupon->update_meta_data( self::GIVEN_BACK_META, $given );

			/*
			 * AND THE REGISTER ENTRY GOES, in the same save as the credit.
			 *
			 * Without it, an order refunded and then back to completed would
			 * no longer be chargeable: the register would still say
			 * "charged", the guard would skip the credit, and the customer
			 * would keep the goods AND a whole credit. That is what a record
			 * of zero on the order did in the old mechanism, less safely.
			 *
			 * `REFUNDED_META` above remains the bound against a second
			 * refund: both are written by this `save()`, so a replay sees
			 * either both or neither.
			 */
			/*
			 * Re-read, and never rebuilt from a copy taken earlier: this map
			 * carries OTHER orders' charges too, and writing a stale copy back
			 * would quietly drop them -- which would make those orders
			 * chargeable and refundable all over again.
			 */
			$register = $coupon->get_meta( self::TAKEN_BY_META );
			$register = is_array( $register ) ? $register : array();

			unset( $register[ (string) $order_id ] );
			$coupon->update_meta_data( self::TAKEN_BY_META, $register );

			$reg_keys = $coupon->get_meta( self::TAKEN_KEYS_META );
			$reg_keys = is_array( $reg_keys ) ? $reg_keys : array();

			unset( $reg_keys[ (string) $order_id ] );
			$coupon->update_meta_data( self::TAKEN_KEYS_META, $reg_keys );

			$coupon->save();

			/*
			 * AND ONLY NOW ARE THE TWO NOTES SAID.
			 *
			 * They used to be written where the facts they report were
			 * decided, which an adverse review showed was still one write too
			 * early: both sat before this `save()`, the only write that moves
			 * the money. A cut on it left the credit untouched -- 0,00 EUR
			 * returned of 40,00 EUR -- while the order stated that the amount
			 * HAD been returned, and `say_once()`, which now saves its own
			 * marker, made that false statement durable. It is the same
			 * regression the note above says it closed, moved one step rather
			 * than removed.
			 *
			 * A replay repairs the balance, but nothing guarantees a replay,
			 * and until then the shop reads a refund that never happened. So
			 * the notes follow the money, and a cut now loses a true note
			 * rather than persisting a false one -- which is the right way
			 * round for the same reason the audit copy is written early:
			 * over-claiming is recoverable, a false record of a payment is
			 * not.
			 */
			if ( $replaced ) {
				$this->say_once( $order, 'replaced-' . $key, sprintf(
					/* translators: %s: coupon code */
					__( 'Store credit %s: the credit charged on this order has been deleted and recreated since, so what was taken has been returned to the new one. Check its balance — it may now hold more than it ever lent.', 'ubac-credit' ),
					$code
				) );
			}

			if ( $say_expired ) {
				$this->say_once( $order, 'expired-' . $key, sprintf(
					/* translators: %s: coupon code */
					__( 'Store credit %s: the amount was returned, but that credit has expired and cannot be spent as it stands. Give it a new expiry date for the customer to use it.', 'ubac-credit' ),
					$code
				) );
			}

			// Re-read for the same reason as the register above: this map
			// carries the other codes on this order.
			$taken_map = $order->get_meta( self::TAKEN_META );
			$taken_map = is_array( $taken_map ) ? $taken_map : array();

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
			if ( $port_seul ) {
				$this->say_once( $order, 'port-seul-' . $key, sprintf(
					/* translators: %s: coupon code */
					__( 'Store credit %s: this order was marked refunded, but nothing that was actually refunded covers any goods — only shipping, fees or an amount with no line attached. The full amount has been returned to the credit anyway. Check that the goods really came back.', 'ubac-credit' ),
					$code
				) );
			}

			$this->push_history( $coupon, $order_id, -$give_back, $new_amount );

			$order->add_order_note(
				sprintf(
					/* translators: 1: coupon code, 2: restored amount */
					__( 'Store credit %1$s: order cancelled or refunded, %2$s returned to the coupon.', 'ubac-credit' ),
					$code,
					wc_price( $give_back )
				)
			);

			$restored = true;
		}

		if ( $restored ) {

			/*
			 * And the mirror: the refund clears `processed`, in the same save
			 * as its own flag.
			 *
			 * This is the decision the charge's own comment left pending, in
			 * so many words: "the refund must clear
			 * `_ubac_credit_processed` in the same save that sets its own
			 * marker, and that is the pending decision about those two flags".
			 * It is taken, and what makes it safe is the register on the
			 * credit: an order back to completed is chargeable again because
			 * the refund removed its entry from the register, and a second
			 * charge against an entry still present is still refused.
			 */
			$order->update_meta_data( '_ubac_credit_restored', 'yes' );
			$order->update_meta_data( '_ubac_credit_processed', '' );
			$order->save();
		}
	}

	/**
	 * Writes an order note once, however often the hook fires.
	 *
	 * Several of this plugin's messages report a REFUSAL -- nothing charged,
	 * nothing returned -- and a refusal is by nature repeatable: the order
	 * keeps its status, so `cancelled`, `refunded` and a second `cancelled`
	 * all come back through here and all refuse again. Measured: five passes,
	 * five identical notes, with nothing bounding them. A note that repeats
	 * stops being read, which defeats the point of writing it.
	 *
	 * The marker is an order meta rather than a read of the existing notes,
	 * because notes live in the comments table and reading them back on every
	 * hook costs a query to say nothing. If the order save that carries this
	 * marker is lost, the note can be written a second time -- which is the
	 * harmless side of that trade.
	 *
	 * @param WC_Order $order The order to annotate.
	 * @param string   $key   What this message is about, unique per order.
	 * @param string   $note  The message, already translated.
	 * @return void
	 */
	/**
	 * What the credit's register says about this order, in one word.
	 *
	 * Two places read the register: `charge_record()` and
	 * `get_reserved_amount()`. They had drifted -- the second compared only
	 * the order id, so an entry left by a vanished order excluded a pending
	 * namesake from the reserved amount while `charge_record()` held that
	 * same order to be chargeable: measured, 0,00 EUR reserved instead of
	 * 30,00 EUR, and the shop ships against a balance already promised. One
	 * reading, in one place, is the only way that stays fixed.
	 *
	 * WHY THE KEY MAP IS ASKED *WHETHER IT KNOWS*, AND NOT MERELY WHAT IT SAYS.
	 *
	 * An adverse review found the previous version of this asking the ORDER
	 * to corroborate whenever the two keys could not be compared -- which put
	 * an unconditional proof back at the mercy of order metas, the exact
	 * mistake a previous review had already closed here, and destroyed
	 * 40,00 EUR of a customer's credit when those metas were gone.
	 *
	 * The discriminator is not whether a key is missing but WHY. An entry the
	 * key map does not know at all carries no suspicion: no id reuse can be
	 * hiding there, because reuse is precisely what the map was added to
	 * record. An entry the map DOES know a key for, facing an order that
	 * cannot produce one, is the real signal of a renumbered namesake -- and
	 * only there is corroboration worth asking for.
	 *
	 * @param WC_Coupon|null $coupon   The credit in hand, if any.
	 * @param WC_Order       $order    The order.
	 * @param int            $order_id Its id.
	 *
	 * @return string One of: `absent` (the register says nothing about this
	 *                order), `speaks` (it does, about this order),
	 *                `other-order` (the id came back on a different order),
	 *                `unmatched` (it speaks, and nothing can tie it to the
	 *                order in hand).
	 */
	private function register_verdict( $coupon, WC_Order $order, $order_id, $code = '' ) {

		$register = $coupon ? $coupon->get_meta( self::TAKEN_BY_META ) : array();
		$register = is_array( $register ) ? $register : array();

		if ( ! array_key_exists( (string) $order_id, $register ) ) {
			return 'absent';
		}

		$reg_keys = $coupon ? $coupon->get_meta( self::TAKEN_KEYS_META ) : array();
		$reg_keys = is_array( $reg_keys ) ? $reg_keys : array();

		$known = array_key_exists( (string) $order_id, $reg_keys );
		$noted = $known ? (string) $reg_keys[ (string) $order_id ] : '';
		$mine  = (string) $order->get_order_key();

		// No key was ever recorded for this entry: there is nothing to doubt.
		if ( ! $known || '' === $noted ) {
			return 'speaks';
		}

		/*
		 * A key was recorded and this order has none: the one real signal of a
		 * reused id -- AND THE ORDER'S OWN RECORDS SEPARATE THE TWO STATES IT
		 * STILL COVERS.
		 *
		 * An order that merely LOST its key carries its own records of the
		 * charge; a renumbered order that reused the id carries nothing. The
		 * first is the credit speaking about this very order, and every reader
		 * should treat it as such -- which is why corroboration lives here
		 * now, in the one reading, rather than in one caller that computed it
		 * while another threw it away. An adverse review measured what that
		 * cost: 60,00 EUR of goods leaving against a credit the charge would
		 * refuse to debit, because the basket had no way to tell the two
		 * states apart.
		 *
		 * The order is asked only to SUSPECT, never to authorise: failing to
		 * corroborate can make a reader reserve more, or pay out less, and
		 * never pay out more. The register keeps its primacy.
		 */
		if ( '' === $mine ) {

			$key       = wc_format_coupon_code( $code );
			$coupon_id = $coupon ? (int) $coupon->get_id() : 0;

			$on_credit = $order->get_meta( self::REGISTER_CODES_META );
			$on_credit = is_array( $on_credit ) ? $on_credit : array();

			$audit = $order->get_meta( self::TAKEN_META );
			$audit = is_array( $audit ) ? $audit : array();

			$corroborated = ( isset( $on_credit[ $key ] ) && $coupon_id
					&& is_scalar( $on_credit[ $key ] )
					&& (int) $on_credit[ $key ] === (int) $coupon_id )
				|| array_key_exists( $key, $audit );

			return $corroborated ? 'speaks' : 'unmatched';
		}

		return $noted === $mine ? 'speaks' : 'other-order';
	}

	protected function say_once( $order, $key, $note ) {

		$said = $order->get_meta( self::SAID_META );
		$said = is_array( $said ) ? $said : array();

		if ( in_array( (string) $key, array_map( 'strval', $said ), true ) ) {
			return;
		}

		$said[] = (string) $key;
		$order->update_meta_data( self::SAID_META, array_values( array_unique( $said ) ) );
		$order->add_order_note( $note );

		/*
		 * AND THE MARKER IS SAVED HERE, not left to the callers.
		 *
		 * Both callers save the order only when something moved -- under
		 * `if ( $processed )` and `if ( $restored )`. A pass that merely
		 * REFUSES therefore never persisted its marker, and the next request
		 * said the same thing again: measured over five requests, five
		 * identical notes, for "already charged", "already refunded",
		 * "cannot be tied to this order" and "coupon not found" alike. That
		 * is the very regression this method exists to prevent, and it came
		 * back through the door of who saves.
		 *
		 * Saving here also flushes whatever order metas were pending, which
		 * is in the safe direction and by design: the order's record is
		 * written before the money moves, so it may over-claim and never
		 * under-claim, and the credit's register overrides it in either case.
		 */
		$order->save();
	}

	/**
	 * What this credit was charged for this order, and on whose word.
	 *
	 * THE REGISTER THAT SPEAKS IS UNCONDITIONAL; ONLY ITS SILENCE IS ARBITRATED.
	 *
	 * Found by an adverse review, after two earlier ones had each found real
	 * money here. The register lives on the credit and is written by the same
	 * `save()` as the balance, so an entry in it cannot exist unless the money
	 * moved. The first version of this reasoning nevertheless believed it only
	 * when `REGISTER_CODES_META` -- a meta on the ORDER -- agreed that the
	 * credit answering to the code was the one charged. That made an
	 * unconditional proof conditional on an unrelated record surviving, and
	 * when it did not survive, both sides misread the SAME state:
	 *
	 *   - the refund found no record it would trust, concluded nothing was
	 *     owed, and kept 40,00 EUR from the customer with no note;
	 *   - the charge found no record either, and took the same 40,00 EUR a
	 *     second time -- then rewrote the register, so the refund would only
	 *     ever give one of the two back.
	 *
	 * The order's record is needed for one question only, and it is a question
	 * about silence: this credit's register says nothing about this order --
	 * is that because it was never charged, or because the credit now
	 * answering to the code is not the one that was charged?
	 *
	 * @param WC_Coupon|null $coupon    The credit in hand, if any.
	 * @param int            $coupon_id Its id, 0 when unknown.
	 * @param WC_Order       $order     The order.
	 * @param int            $order_id  Its id.
	 * @param string         $code      The coupon code as the order carries it.
	 *
	 * @return array The recorded amount at index 0 -- returned exactly as it
	 *               was stored, so the caller can tell a readable record from
	 *               one it must not trust, and null when NOTHING claims a
	 *               charge for this credit on this order -- and at index 1
	 *               whether the credit answering to the code is not the one
	 *               that was charged.
	 */
	private function charge_record( $coupon, $coupon_id, WC_Order $order, $order_id, $code ) {

		$key      = wc_format_coupon_code( $code );
		$register = $coupon ? $coupon->get_meta( self::TAKEN_BY_META ) : array();
		$register = is_array( $register ) ? $register : array();

		$verdict = $this->register_verdict( $coupon, $order, $order_id, $code );

		// The credit's own register speaks about this order: nothing overrides it.
		if ( 'speaks' === $verdict ) {
			return array( $register[ (string) $order_id ], false, false );
		}

		/*
		 * It speaks, and nothing ties it to the order in hand. The entry is
		 * still believed -- the other reading would charge a customer twice --
		 * but the callers are told so, and they then part company,
		 * deliberately: believing costs the shop its goods, paying out
		 * creates money.
		 */
		if ( 'unmatched' === $verdict ) {
			return array( $register[ (string) $order_id ], false, true );
		}

		// The id came back on a different order, which this credit never charged.
		if ( 'other-order' === $verdict ) {
			return array( null, false, false );
		}

		$on_credit = $order->get_meta( self::REGISTER_CODES_META );
		$on_credit = is_array( $on_credit ) ? $on_credit : array();
		$claimed   = ( isset( $on_credit[ $key ] ) && $coupon_id ) ? (int) $on_credit[ $key ] : 0;

		/*
		 * The order points at THIS credit, whose register is silent: it was
		 * never charged. This is the one case where silence is an answer.
		 *
		 * AND THE ORDER'S AUDIT COPY MUST NOT BE CONSULTED HERE, which was
		 * tried and measured. Requiring the audit copy to be silent too --
		 * "agreed silence" -- looks stricter and creates money: the order
		 * record is written and saved BEFORE the credit's, deliberately, so
		 * that a charge is recorded before it happens. A cut between the two
		 * therefore leaves the audit copy claiming an amount that was never
		 * taken, and hearing it refunds money that never moved. The sweep put
		 * the figure on it at once: 140,00 EUR on a 100,00 EUR credit, at
		 * `coupon` rank 1 of `process_order`.
		 *
		 * The asymmetry is the whole design: the order speaks first and may
		 * therefore be wrong about what happened; the credit speaks last and
		 * cannot be.
		 */
		if ( $claimed && $claimed === (int) $coupon_id ) {
			return array( null, false, false );
		}

		// Either charged by a version that kept no register, or the credit has
		// been replaced since. The order's audit copy, filed by code, is then
		// the only record left; its own absence still means nothing claims a
		// charge.
		$taken_map = $order->get_meta( self::TAKEN_META );
		$taken_map = is_array( $taken_map ) ? $taken_map : array();

		return array(
			array_key_exists( $key, $taken_map ) ? $taken_map[ $key ] : null,
			(bool) ( $claimed && $claimed !== (int) $coupon_id ),
			false,
		);
	}

	/**
	 * A partial refund gives back the credit's share of it.
	 *
	 * ## Why a share, and which share
	 *
	 * A `fixed_cart` coupon lowers the cart, so an order paid partly by credit
	 * carries a total that is ALREADY net of it: a 100,00 EUR basket with a
	 * 40,00 EUR credit becomes a 60,00 EUR order, and 60,00 EUR is what the
	 * card paid. WooCommerce will never let a refund exceed that 60,00 EUR,
	 * so no partial refund can ever "spill over" onto the credit by arithmetic
	 * alone -- a first design assumed it could, and it was wrong.
	 *
	 * What a refund of 15,00 EUR on a 60,00 EUR order really says is that a
	 * quarter of the order went back. A quarter of the goods was paid for by
	 * the credit, so a quarter of the credit is owed: 10,00 EUR. The share is
	 * therefore `taken * refunded / total`, and it degenerates exactly right
	 * -- refund the whole 60,00 EUR of cash and the whole 40,00 EUR of credit
	 * comes back, which is what setting the order to refunded already did.
	 *
	 * ## What bounds it
	 *
	 * `GIVEN_BACK_META` is read before anything moves and written by the save
	 * that moves it. Refunds arrive in instalments, in any order, and may be
	 * followed by a full refund: the amount owed is always
	 * `min( taken, share ) - already_given`, never more than was taken, and
	 * never twice.
	 *
	 * ## What it does NOT do
	 *
	 * It does not clear the register, and it does not mark the order as
	 * refunded: the order is still charged, and a further refund -- or a
	 * cancellation -- must still be able to settle the rest.
	 *
	 * And it never takes credit BACK. Deleting a refund in WooCommerce lowers
	 * the refunded total, which would make the share smaller than what has
	 * already been returned. Clawing it back could take money the customer has
	 * since spent, so the balance stands and the order says what happened --
	 * the same choice this plugin makes everywhere: the recoverable harm over
	 * the irrecoverable one.
	 *
	 * @param int $order_id  The order being refunded.
	 * @param int $refund_id The refund that was just created.
	 * @return void
	 */
	public function refund_partially( $order_id, $refund_id = 0 ) {

		unset( $refund_id );

		if ( ! $this->is_enabled() ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		/*
		 * A fully refunded order is the other path's business: it clears the
		 * register and marks the order, which this one deliberately does not.
		 * Leaving it to the status hook also keeps a single place responsible
		 * for freeing an order.
		 */
		if ( $order->has_status( array( 'refunded', 'cancelled', 'failed' ) ) ) {
			return;
		}

		$decimals = wc_get_price_decimals();

		/*
		 * THE SHARE IS MEASURED ON THE GOODS, NOT ON THE ORDER.
		 *
		 * This used `$order->get_total()` and `get_total_refunded()`, and an
		 * adverse review showed what that cost. An order total is goods PLUS
		 * shipping, fees and tax, and a `fixed_cart` coupon never touches
		 * shipping -- so the two sides of the ratio measured different things
		 * from what the credit had lent. The commonest gesture in retail
		 * broke it: a basket of 100,00 EUR paid entirely by a 100,00 EUR
		 * credit with 10,00 EUR of shipping on the card, and the shop refunds
		 * the shipping because the parcel was late. The order total is
		 * 10,00 EUR, the refund is 10,00 EUR, the share reads as the whole
		 * order -- and the credit came back in full while the customer kept
		 * the goods. **100,00 EUR created**, and the order freed afterwards,
		 * so nothing would ever take it back.
		 *
		 * It cost the customer in the other direction too: a full return with
		 * the shipping kept, which is standard practice, gave 34,29 EUR of a
		 * 40,00 EUR charge back -- and because the order never reaches the
		 * `refunded` status, no later pass would ever repair it.
		 *
		 * So both sides now read the LINE ITEMS only: what the card paid for
		 * goods, and what has been refunded on goods. Shipping and fees are
		 * not what the credit paid for, so they cannot be what measures its
		 * return.
		 */
		$assiette = 0.0;
		$rendu    = 0.0;

		/*
		 * BOTH SIDES WITHOUT TAX, so the tax cancels and nothing has to be
		 * reconciled. A first version added the refunded tax per item and
		 * called `get_tax_refunded_for_item( $item_id )` -- which takes a TAX
		 * RATE id, not an item, and threw on a real WooCommerce. The bench's
		 * double had accepted the invented signature, which is the same
		 * lesson as a double that is stricter than reality, arriving the
		 * other way round: a double that is more LENIENT hides a crash.
		 *
		 * The ratio is unaffected: tax is proportional to the line, so a
		 * ratio of two amounts that both exclude it is the same number.
		 */
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$assiette += (float) $item->get_total();
			$rendu    += (float) $order->get_total_refunded_for_item( $item_id );
		}

		$assiette = round( $assiette, $decimals );
		$rendu    = round( $rendu, $decimals );

		/*
		 * No goods for the card to have paid for -- an order whose items are
		 * wholly covered by the credit nets to zero -- so no refund of
		 * shipping or fees can be a share of what the credit lent. That order
		 * is the status hook's business when it is cancelled or refunded.
		 */
		if ( $assiette <= 0 || $rendu <= 0 ) {
			return;
		}

		$part = min( 1.0, $rendu / $assiette );

		foreach ( $this->claims_on( $order, $decimals ) as $claim ) {

			$code      = $claim['code'];
			$key       = wc_format_coupon_code( $code );
			$coupon_id = wc_get_coupon_id_by_code( $code );

			if ( ! $coupon_id ) {
				continue;
			}

			$coupon = new WC_Coupon( $coupon_id );

			if ( ! $this->is_ubac_credit_coupon( $coupon ) ) {
				continue;
			}

			/* Already settled in full by the other path? Nothing to do. */
			$done = $coupon->get_meta( self::REFUNDED_META );
			if ( is_array( $done ) && in_array( (int) $order_id, array_map( 'intval', $done ), true ) ) {
				continue;
			}

			list( $recorded, $replaced, $unmatched ) = $this->charge_record( $coupon, $coupon_id, $order, $order_id, $code );

			/*
			 * The same reading as everywhere else, and the same refusal: a
			 * charge that cannot be tied to this order is not paid out here
			 * either, because paying it would hand over an amount another
			 * order took.
			 */
			/*
			 * A charge that cannot be tied to this order is not paid out
			 * here, because paying it would hand over an amount another order
			 * took -- the same refusal as a full refund. AND IT IS SAID, for
			 * the same reason it is said there: an adverse review measured
			 * 20,00 EUR owed and not returned with no note at all, and the
			 * whole charge disappearing in silence over the instalments that
			 * followed. `null` is different and stays silent: nothing claims a
			 * charge, so there is nothing to report.
			 */
			if ( $unmatched ) {
				$this->say_once( $order, 'unmatched-' . $key, sprintf(
					/* translators: %s: coupon code */
					__( 'Store credit %s: a charge is recorded against this order id but cannot be tied to this order, so nothing was returned automatically — returning it could hand over an amount another order took. Check whether this order was paid by that credit and refund it by hand if so.', 'ubac-credit' ),
					$code
				) );
				continue;
			}

			if ( null === $recorded ) {
				continue;
			}

			if ( ! is_numeric( $recorded ) || ! is_finite( (float) $recorded ) ) {
				continue;
			}

			$taken = round( (float) $recorded, $decimals );

			if ( $taken <= 0 ) {
				continue;
			}

			$given = $coupon->get_meta( self::GIVEN_BACK_META );
			$given = is_array( $given ) ? $given : array();
			$deja  = $this->already_given( $given, $order_id, $taken, $decimals );

			if ( $this->given_damaged( $given, $order_id ) ) {
				$this->say_once( $order, 'given-damaged-' . $key, sprintf(
					/* translators: %s: coupon code */
					__( 'Store credit %s: the record of what had already been returned on this order could not be read, so it was treated as nothing returned yet. Check that credit\'s balance and its history.', 'ubac-credit' ),
					$code
				) );
			}


			$du    = round( min( $taken, $taken * $part ), $decimals );
			$delta = round( $du - $deja, $decimals );

			if ( $delta <= 0 ) {
				continue;
			}

			$nouveau = round( (float) $coupon->get_amount() + $delta, $decimals );

			$coupon->set_amount( $nouveau );
			$coupon->set_usage_count( 0 );

			$rendu_total                 = round( $deja + $delta, $decimals );
			$given[ (string) $order_id ] = (string) $rendu_total;
			$coupon->update_meta_data( self::GIVEN_BACK_META, $given );

			/*
			 * AND WHERE THE WHOLE CHARGE IS BACK, THE ORDER IS FREED HERE TOO.
			 *
			 * This path deliberately leaves the register alone, because a
			 * further refund must still be able to settle the rest. When the
			 * share reaches the whole charge there IS no rest, and leaving the
			 * entry made a state with no way out: the register still claimed a
			 * charge that had been returned in full, so the order could
			 * neither be refunded again nor charged again -- re-completing it
			 * sent the goods out without taking a cent. Measured by the bench
			 * the moment the case was written down.
			 *
			 * The rule is therefore one rule on both paths: an order is freed
			 * on a credit exactly when the whole of its charge is back.
			 */
			$solde_entier = ( $rendu_total >= $taken );

			if ( $solde_entier ) {
				$register = $coupon->get_meta( self::TAKEN_BY_META );
				$register = is_array( $register ) ? $register : array();
				unset( $register[ (string) $order_id ] );
				$coupon->update_meta_data( self::TAKEN_BY_META, $register );

				$reg_keys = $coupon->get_meta( self::TAKEN_KEYS_META );
				$reg_keys = is_array( $reg_keys ) ? $reg_keys : array();
				unset( $reg_keys[ (string) $order_id ] );
				$coupon->update_meta_data( self::TAKEN_KEYS_META, $reg_keys );

				$refunded   = $coupon->get_meta( self::REFUNDED_META );
				$refunded   = is_array( $refunded ) ? $refunded : array();
				$refunded[] = (int) $order_id;
				$coupon->update_meta_data( self::REFUNDED_META, array_values( array_unique( $refunded ) ) );
			}

			/*
			 * The expiry is pushed out the same way a full refund pushes it,
			 * and never pulled in: money returned onto an expired credit is
			 * not money returned.
			 */
			$jours   = $this->extension_days();
			$expire  = $coupon->get_date_expires();

			if ( $jours > 0 && $nouveau > 0 && $expire ) {
				$fenetre = time() + ( $jours * DAY_IN_SECONDS );
				if ( $expire->getTimestamp() < $fenetre ) {
					$coupon->set_date_expires( $fenetre );
				}
			}

			$coupon->save();
			$this->clear_reserved_cache( $coupon->get_id() );

			$this->push_history( $coupon, $order_id, -$delta, $nouveau );

			$order->add_order_note(
				sprintf(
					/* translators: 1: formatted amount, 2: coupon code, 3: percentage of the order refunded */
					__( 'Ubac Store Credit: %1$s returned to %2$s, the credit\'s share of a partial refund covering %3$s of this order.', 'ubac-credit' ),
					wc_price( $delta ),
					$code,
					$this->part_lisible( $part )
				)
			);

			if ( $replaced ) {
				$this->say_once( $order, 'replaced-' . $key, sprintf(
					/* translators: %s: coupon code */
					__( 'Store credit %s: the credit charged on this order has been deleted and recreated since, so what was taken has been returned to the new one. Check its balance — it may now hold more than it ever lent.', 'ubac-credit' ),
					$code
				) );
			}
		}
	}

	/**
	 * The share, written so it never reads as nothing.
	 *
	 * `round( $part * 100 )` printed "covering 0% of this order" for any
	 * share under half a percent -- measured on lines of 6 000,00 EUR with
	 * 1,00 EUR refunded, where 0,01 EUR really did go back. A note that says
	 * zero about an amount that moved is the kind of untruth this file spends
	 * its comments refusing.
	 *
	 * @param float $part The share, between 0 and 1.
	 * @return string
	 */
	private function part_lisible( $part ) {

		$pourcent = (float) $part * 100;

		if ( $pourcent > 0 && $pourcent < 1 ) {
			return rtrim( rtrim( number_format( $pourcent, 2, '.', '' ), '0' ), '.' ) . '%';
		}

		return round( $pourcent ) . '%';
	}

	/**
	 * Is the record of what was given back unusable?
	 *
	 * Every other amount in this file is guarded by `is_numeric()` AND
	 * `is_finite()`, and bounded, and the docblocks say at length why. This
	 * one was guarded by `is_numeric()` alone, and an adverse review priced
	 * the omission:
	 *
	 *   - a NEGATIVE value (`-999`) made the amount owed `du + 999`, which
	 *     nothing bounded: a 100,00 EUR credit was carried to 1 099,00 EUR,
	 *     and the note announced it;
	 *   - `'1e400'` reads as INF, so every delta came out at or below zero:
	 *     the refund returned nothing and said nothing -- 40,00 EUR kept from
	 *     a customer in silence -- and the order was left neither refundable
	 *     nor chargeable.
	 *
	 * No path inside this plugin writes such a value; it writes
	 * `(string) round( … )`, always positive. The cause is the one every
	 * other guard here is for: an import, a partial dump, a third-party tool,
	 * a hand edit.
	 *
	 * A value that cannot be read is treated as nothing returned yet, which
	 * errs towards paying the customer, and it is SAID on the order, because
	 * a silent refund is what this module refuses everywhere.
	 *
	 * @param array $given    The record, as stored.
	 * @param int   $order_id The order.
	 * @return bool
	 */
	private function given_damaged( array $given, $order_id ) {

		$cle = (string) $order_id;

		if ( ! array_key_exists( $cle, $given ) ) {
			return false;
		}

		return ( ! is_numeric( $given[ $cle ] ) || ! is_finite( (float) $given[ $cle ] )
			|| (float) $given[ $cle ] < 0 );
	}

	/**
	 * How much of this charge has already been returned, read safely.
	 *
	 * See `given_damaged()` for what an unreadable record costs.
	 *
	 * @param array $given    The record, as stored.
	 * @param int   $order_id The order.
	 * @param float $taken    What was charged; the record cannot exceed it.
	 * @param int   $decimals Price decimals.
	 * @return float
	 */
	private function already_given( array $given, $order_id, $taken, $decimals ) {

		$cle = (string) $order_id;

		if ( ! array_key_exists( $cle, $given ) ) {
			return 0.0;
		}

		$brut = $given[ $cle ];

		if ( ! is_numeric( $brut ) || ! is_finite( (float) $brut ) ) {
			return 0.0;
		}

		/*
		 * Bounded at both ends: never negative, and never more than was
		 * taken. Either would turn a damaged record into money.
		 */
		return max( 0.0, min( (float) $taken, round( (float) $brut, $decimals ) ) );
	}

	/**
	 * Everything that claims this order took something from a credit.
	 *
	 * The coupon lines, plus every code named by either record on the order.
	 * Taking a coupon off an order in the admin DELETES its line rather than
	 * zeroing it, so a refund that walked the lines alone gave nothing back
	 * and said nothing -- measured at 40,00 EUR kept from a customer.
	 *
	 * Shared by the refund and the partial refund, in one place, for the
	 * reason `register_verdict()` is shared: two readers of one state that
	 * each build their own view will drift, and this plugin has already paid
	 * twice for that.
	 *
	 * @param WC_Order $order    The order.
	 * @param int      $decimals Price decimals.
	 * @return array Keyed by normalised code: code, consumed, recorded.
	 */
	private function claims_on( WC_Order $order, $decimals ) {

		$claims = array();

		foreach ( $order->get_items( 'coupon' ) as $item ) {
			$claims[ wc_format_coupon_code( $item->get_code() ) ] = array(
				'code'     => $item->get_code(),
				'consumed' => round( (float) $item->get_discount() + (float) $item->get_discount_tax(), $decimals ),
				'recorded' => false,
			);
		}

		foreach ( array( self::TAKEN_META, self::REGISTER_CODES_META ) as $map_key ) {
			$map = $order->get_meta( $map_key );

			if ( ! is_array( $map ) ) {
				continue;
			}

			foreach ( array_keys( $map ) as $noted_code ) {
				$noted_key = wc_format_coupon_code( (string) $noted_code );

				if ( isset( $claims[ $noted_key ] ) ) {
					$claims[ $noted_key ]['recorded'] = true;
					continue;
				}

				$claims[ $noted_key ] = array(
					'code'     => (string) $noted_code,
					'consumed' => 0.0,
					'recorded' => true,
				);
			}
		}

		return $claims;
	}

	/**
	 * A credit's limit is its balance, not a number of uses.
	 *
	 * WooCommerce refuses a coupon when the same customer has already used it
	 * as many times as `usage_limit_per_user` allows, counting the `_used_by`
	 * rows it keeps. For a discount voucher that is exactly right. For a
	 * credit it is the wrong question: what is left to spend is the balance,
	 * and a customer who spends 40,00 EUR of a 100,00 EUR credit must be able
	 * to come back for the other 60,00 EUR.
	 *
	 * This is answered here rather than by editing the list of users, because
	 * measurement said the list cannot be edited: WooCommerce exposes no API
	 * that removes a `_used_by` row, and the two that look as though they
	 * might -- a hand-written meta, then `decrease_usage_count()` -- were both
	 * tried against a real WooCommerce and both left the row in place. A
	 * filter on the check itself is the supported way, and it has the
	 * advantage of saying what the rule IS instead of covering its traces.
	 *
	 * Only credits are affected: an ordinary coupon keeps its per-user limit.
	 *
	 * @param bool      $check   Whether WooCommerce should apply the limit.
	 * @param int       $user_id The customer being checked.
	 * @param WC_Coupon $coupon  The coupon being checked.
	 * @return bool
	 */
	public function allow_repeat_use( $check, $user_id, $coupon ) {

		if ( $coupon instanceof WC_Coupon && $this->is_ubac_credit_coupon( $coupon ) ) {
			return false;
		}

		return $check;
	}

	/**
	 * Append an entry to the coupon's history meta.
	 *
	 * Kept on the coupon itself (_ubac_credit_history) rather than in a
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
		$history   = $coupon->get_meta( '_ubac_credit_history' );
		$history   = is_array( $history ) ? $history : array();
		$history[] = array(
			'date'        => current_time( 'mysql' ),
			'order_id'    => $order_id,
			'consumed'    => $consumed,
			'new_balance' => $new_balance,
		);
		$coupon->update_meta_data( '_ubac_credit_history', $history );
		$coupon->save();
	}
}
