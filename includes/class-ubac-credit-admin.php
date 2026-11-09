<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The admin screens: settings, the store-credit table, and the coupon history.
 */
class Ubac_Credit_Admin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		/*
		 * The screen is reachable with manage_woocommerce, but options.php asks
		 * for manage_options by default, so a shop_manager could tick a box,
		 * press save and be refused -- having been shown the choice. The
		 * capability that saves is the capability that opens.
		 *
		 * Registered here rather than inside register_settings(), where it sat
		 * until 08/11/2026. It worked, because options.php loads admin.php and
		 * so fires admin_init before reading the filter -- but it depended on
		 * that ordering, and would have failed silently had the hook moved to a
		 * later priority or behind a screen condition. ubac-composite-products
		 * and ubac-pricing both register theirs at this level.
		 */
		add_filter(
			'option_page_capability_ubac_credit_settings_group',
			static function () {
				return 'manage_woocommerce';
			}
		);

		add_action( 'add_meta_boxes', array( $this, 'add_history_metabox' ) );
	}

	public function add_settings_page() {
		add_submenu_page(
			'woocommerce',
			__( 'DahuConcept Store Credit', 'dahuconcept-store-credit' ),
			__( 'Store credit', 'dahuconcept-store-credit' ),
			'manage_woocommerce',
			'ubac-credit',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Load the screen's stylesheet, on that screen only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin( $hook ) {

		if ( 'woocommerce_page_ubac-credit' !== $hook ) {
			return;
		}

		// The family sheet first, and its own second: the plugin's own rules
		// are written to sit on top of the shared components, so the order is
		// a dependency and not a hope. One handle across the family -- the
		// file is byte-identical everywhere, so whichever plugin loads it
		// first serves all of them.
		wp_enqueue_style(
			'ubac-ui',
			plugins_url( 'assets/css/ubac-ui.css', UBAC_CREDIT_FILE ),
			array(),
			UBAC_CREDIT_VERSION
		);

		wp_enqueue_style(
			'ubac-credit-admin',
			plugins_url( 'assets/css/admin.css', UBAC_CREDIT_FILE ),
			array( 'ubac-ui' ),
			UBAC_CREDIT_VERSION
		);
	}

	public function register_settings() {
		register_setting( 'ubac_credit_settings_group', 'ubac_credit_settings', array( $this, 'sanitize_settings' ) );
	}

	public function sanitize_settings( $input ) {
		$output                   = array();
		$output['enabled']        = ( isset( $input['enabled'] ) && 'yes' === $input['enabled'] ) ? 'yes' : 'no';
		$output['extension_days'] = isset( $input['extension_days'] ) ? max( 0, (int) $input['extension_days'] ) : 0;

		// The uninstall choice. Stored as 'yes'/'no' like `enabled`, and read
		// by uninstall.php out of this same array, so there is one place to
		// set it and one place to read it.
		$output['delete_data_on_uninstall'] = ( isset( $input['delete_data_on_uninstall'] ) && 'yes' === $input['delete_data_on_uninstall'] ) ? 'yes' : 'no';

		return $output;
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$settings       = get_option( 'ubac_credit_settings', array() );
		$enabled        = isset( $settings['enabled'] ) ? $settings['enabled'] : 'yes';
		$extension_days = isset( $settings['extension_days'] ) ? (int) $settings['extension_days'] : 0;
		$delete_on_uninstall = isset( $settings['delete_data_on_uninstall'] ) ? $settings['delete_data_on_uninstall'] : 'no';

		?>
		<div class="wrap ubac-ui">
			<div class="ubac-ui-head">
				<h1 class="ubac-ui-head__title"><?php esc_html_e( 'Store credit', 'dahuconcept-store-credit' ); ?></h1>
				<p class="ubac-ui-head__sub"><?php esc_html_e( 'Credit a customer can spend at the checkout, and where it came from.', 'dahuconcept-store-credit' ); ?></p>
			</div>

			<hr class="wp-header-end">
			<form method="post" action="options.php">
				<?php settings_fields( 'ubac_credit_settings_group' ); ?>
				<div class="ubac-ui-card">
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable DahuConcept Store Credit', 'dahuconcept-store-credit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="ubac_credit_settings[enabled]" value="yes" <?php checked( $enabled, 'yes' ); ?> />
								<?php esc_html_e( 'Automatically carry the remaining balance forward after payment', 'dahuconcept-store-credit' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Validity extension', 'dahuconcept-store-credit' ); ?></th>
						<td>
							<input type="number" min="0" step="1" name="ubac_credit_settings[extension_days]" value="<?php echo esc_attr( $extension_days ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Days added to the expiry date on each partial use. 0 leaves the expiry date alone.', 'dahuconcept-store-credit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstalling', 'dahuconcept-store-credit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="ubac_credit_settings[delete_data_on_uninstall]" value="yes" <?php checked( $delete_on_uninstall, 'yes' ); ?> />
								<?php esc_html_e( "Delete this plugin's data when the plugin is deleted", 'dahuconcept-store-credit' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off, and worth leaving off. What goes is every credit balance and the history of where each one came from — money owed to customers. Deleting the plugin is usually a step in reinstalling it.', 'dahuconcept-store-credit' ); ?></p>
						</td>
					</tr>
				</table>
				</div>
				<?php submit_button( null, 'ubac-ui-btn ubac-ui-btn--go' ); ?>
			</form>

			<div class="ubac-ui-card" id="ubac-credit-list">
				<div class="ubac-ui-card__head">
					<h2 class="ubac-ui-card__title"><?php esc_html_e( 'Active store credits', 'dahuconcept-store-credit' ); ?></h2>
				</div>
				<?php $this->render_coupons_table(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Query argument carrying the page of the table.
	 */
	const PAGE_VAR = 'ubac_credit_page';

	/**
	 * How many store credits one page of the table holds.
	 *
	 * WHY THIS CONSTANT EXISTS. A shop that issues a credit on every refund
	 * holds as many of these coupons as it has made refunds. The unpaginated
	 * query this screen used to run read every one of them and built a
	 * WC_Coupon for each: on a shop with ten thousand credits the screen
	 * fetched ten thousand posts and their meta to show a table nobody
	 * scrolls to the bottom of. Fifty is the page a WordPress list table
	 * uses, so the screen behaves like the ones next to it.
	 */
	const PER_PAGE = 50;

	private function render_coupons_table() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a page number on a read-only screen.
		$paged = isset( $_GET[ self::PAGE_VAR ] ) ? max( 1, (int) $_GET[ self::PAGE_VAR ] ) : 1;

		$query = new WP_Query(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => self::PER_PAGE,
				'paged'          => $paged,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the only way to list credits; admin screen, bounded by PER_PAGE and paged.
				'meta_query'     => array(
					array(
						'key'   => '_ubac_credit',
						'value' => 'yes',
					),
				),
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( ! $query->have_posts() ) {
			printf(
				'<p class="ubac-ui-hint">%s</p><p><a class="ubac-ui-btn" href="%s">%s</a></p>',
				esc_html__( 'No store credit recorded yet. A fixed-cart coupon ticked as store credit appears here.', 'dahuconcept-store-credit' ),
				esc_url( admin_url( 'post-new.php?post_type=shop_coupon' ) ),
				esc_html__( 'Create a coupon', 'dahuconcept-store-credit' )
			);
			return;
		}

		?>
		<table class="ubac-ui-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Code', 'dahuconcept-store-credit' ); ?></th>
					<th><?php esc_html_e( 'Current balance', 'dahuconcept-store-credit' ); ?></th>
					<th><?php esc_html_e( 'Expiry date', 'dahuconcept-store-credit' ); ?></th>
					<th><?php esc_html_e( 'Email restriction', 'dahuconcept-store-credit' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $query->posts as $post ) : ?>
					<?php
					$coupon  = new WC_Coupon( $post->ID );
					$expires = $coupon->get_date_expires();
					$emails  = $coupon->get_email_restrictions();
					?>
					<tr>
						<td><?php echo esc_html( $coupon->get_code() ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $coupon->get_amount() ) ); ?></td>
						<td><?php echo $expires ? esc_html( $expires->date_i18n( 'd/m/Y' ) ) : esc_html__( 'Unlimited', 'dahuconcept-store-credit' ); ?></td>
						<td><?php echo $emails ? esc_html( implode( ', ', $emails ) ) : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$this->render_pagination( $paged, (int) $query->max_num_pages );
		wp_reset_postdata();
	}

	/**
	 * The page links below the table.
	 *
	 * Nothing is printed for a single page, so a shop with a handful of
	 * credits sees the screen it saw before. The link text is WordPress's
	 * own, which is why this adds no string of its own to translate.
	 *
	 * @param int $paged Page being shown.
	 * @param int $pages Pages in all.
	 * @return void
	 */
	private function render_pagination( $paged, $pages ) {

		if ( $pages < 2 ) {
			return;
		}

		$links = paginate_links(
			array(
				'base'    => add_query_arg( self::PAGE_VAR, '%#%' ),
				'format'  => '',
				'current' => $paged,
				'total'   => $pages,
				'type'    => 'plain',
			)
		);

		if ( $links ) {
			echo '<p class="ubac-ui-hint tablenav-pages">' . wp_kses_post( $links ) . '</p>';
		}
	}

	public function add_history_metabox() {
		add_meta_box(
			'ubac_credit_history',
			__( 'DahuConcept Store Credit history', 'dahuconcept-store-credit' ),
			array( $this, 'render_history_metabox' ),
			'shop_coupon',
			'normal',
			'default'
		);
	}

	public function render_history_metabox( $post ) {
		$coupon  = new WC_Coupon( $post->ID );
		$history = $coupon->get_meta( '_ubac_credit_history' );

		if ( 'yes' !== $coupon->get_meta( '_ubac_credit' ) ) {
			echo '<p>' . esc_html__( 'This coupon is not marked as store credit.', 'dahuconcept-store-credit' ) . '</p>';
			return;
		}

		if ( empty( $history ) || ! is_array( $history ) ) {
			echo '<p>' . esc_html__( 'No history yet.', 'dahuconcept-store-credit' ) . '</p>';
			return;
		}

		?>
		<table class="ubac-ui-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'dahuconcept-store-credit' ); ?></th>
					<th><?php esc_html_e( 'Order', 'dahuconcept-store-credit' ); ?></th>
					<th><?php esc_html_e( 'Amount used', 'dahuconcept-store-credit' ); ?></th>
					<th><?php esc_html_e( 'New balance', 'dahuconcept-store-credit' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_reverse( $history ) as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( $entry['date'] ); ?></td>
						<td>
							<?php
							$order_id = isset( $entry['order_id'] ) ? (int) $entry['order_id'] : 0;
							$order    = $order_id ? wc_get_order( $order_id ) : false;
							if ( $order ) {
								echo '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order_id ) . '</a>';
							} else {
								echo esc_html( '#' . $order_id );
							}
							?>
						</td>
						<td><?php echo wp_kses_post( wc_price( $entry['consumed'] ) ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $entry['new_balance'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
