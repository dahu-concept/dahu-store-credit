<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The business logic: marking coupons, carrying the balance forward, restoring it.
 */
class Dahu_Store_Credit_Core {

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
			if ( $remaining > 0 ) {
				$coupon->set_amount( $remaining );
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

				$extension_days = $this->extension_days();
				if ( $extension_days > 0 ) {
					$coupon->set_date_expires( time() + ( $extension_days * DAY_IN_SECONDS ) );
				}

				$coupon->save();
				$this->clear_reserved_cache( $coupon->get_id() );

				$this->push_history( $coupon, $order_id, $consumed, $remaining );

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
				$this->push_history( $coupon, $order_id, $consumed, 0 );
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

		if ( 'yes' !== $order->get_meta( '_dahu_store_credit_processed' ) ) {
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

			$new_amount = round( (float) $coupon->get_amount() + $consumed, $decimals );
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

			$coupon->save();
			$this->clear_reserved_cache( $coupon->get_id() );

			$this->push_history( $coupon, $order_id, -$consumed, $new_amount );

			$order->add_order_note(
				sprintf(
					/* translators: 1: coupon code, 2: restored amount */
					__( 'Store credit %1$s: order cancelled or refunded, %2$s returned to the coupon.', 'dahu-store-credit' ),
					$code,
					wc_price( $consumed )
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
