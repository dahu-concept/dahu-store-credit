<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The admin screens: settings, the store-credit table, and the coupon history.
 */
class Dahu_Store_Credit_Admin {

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

		add_action( 'add_meta_boxes', array( $this, 'add_history_metabox' ) );
	}

	public function add_settings_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Dahu Store Credit', 'dahu-store-credit' ),
			__( 'Dahu Store Credit', 'dahu-store-credit' ),
			'manage_woocommerce',
			'dahu-store-credit',
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

		if ( 'woocommerce_page_dahu-store-credit' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'dahu-store-credit-admin',
			plugins_url( 'assets/css/admin.css', DAHU_STORE_CREDIT_FILE ),
			array(),
			DAHU_STORE_CREDIT_VERSION
		);
	}

	public function register_settings() {
		register_setting( 'dahu_store_credit_settings_group', 'dahu_store_credit_settings', array( $this, 'sanitize_settings' ) );
	}

	public function sanitize_settings( $input ) {
		$output                   = array();
		$output['enabled']        = ( isset( $input['enabled'] ) && 'yes' === $input['enabled'] ) ? 'yes' : 'no';
		$output['extension_days'] = isset( $input['extension_days'] ) ? max( 0, (int) $input['extension_days'] ) : 0;
		return $output;
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$settings       = get_option( 'dahu_store_credit_settings', array() );
		$enabled        = isset( $settings['enabled'] ) ? $settings['enabled'] : 'yes';
		$extension_days = isset( $settings['extension_days'] ) ? (int) $settings['extension_days'] : 0;

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Store credit', 'dahu-store-credit' ); ?></h1>

			<p class="dahu-store-credit__intro">
				<?php esc_html_e( 'Dahu Store Credit turns a coupon into a balance a customer spends over several orders, instead of one that is consumed whole on the first.', 'dahu-store-credit' ); ?>
			</p>
			<hr class="wp-header-end">
			<form method="post" action="options.php">
				<?php settings_fields( 'dahu_store_credit_settings_group' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Dahu Store Credit', 'dahu-store-credit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="dahu_store_credit_settings[enabled]" value="yes" <?php checked( $enabled, 'yes' ); ?> />
								<?php esc_html_e( 'Automatically carry the remaining balance forward after payment', 'dahu-store-credit' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Validity extension', 'dahu-store-credit' ); ?></th>
						<td>
							<input type="number" min="0" step="1" name="dahu_store_credit_settings[extension_days]" value="<?php echo esc_attr( $extension_days ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Days added to the expiry date on each partial use. 0 leaves the expiry date alone.', 'dahu-store-credit' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Active store credits', 'dahu-store-credit' ); ?></h2>
			<?php $this->render_coupons_table(); ?>
		</div>
		<?php
	}

	private function render_coupons_table() {
		$query = new WP_Query(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => '_dahu_store_credit',
						'value' => 'yes',
					),
				),
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( ! $query->have_posts() ) {
			echo '<p>' . esc_html__( 'No store credit recorded yet.', 'dahu-store-credit' ) . '</p>';
			return;
		}

		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Code', 'dahu-store-credit' ); ?></th>
					<th><?php esc_html_e( 'Current balance', 'dahu-store-credit' ); ?></th>
					<th><?php esc_html_e( 'Expiry date', 'dahu-store-credit' ); ?></th>
					<th><?php esc_html_e( 'Email restriction', 'dahu-store-credit' ); ?></th>
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
						<td><?php echo $expires ? esc_html( $expires->date_i18n( 'd/m/Y' ) ) : esc_html__( 'Unlimited', 'dahu-store-credit' ); ?></td>
						<td><?php echo $emails ? esc_html( implode( ', ', $emails ) ) : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		wp_reset_postdata();
	}

	public function add_history_metabox() {
		add_meta_box(
			'dahu_store_credit_history',
			__( 'Dahu Store Credit history', 'dahu-store-credit' ),
			array( $this, 'render_history_metabox' ),
			'shop_coupon',
			'normal',
			'default'
		);
	}

	public function render_history_metabox( $post ) {
		$coupon  = new WC_Coupon( $post->ID );
		$history = $coupon->get_meta( '_dahu_store_credit_history' );

		if ( 'yes' !== $coupon->get_meta( '_dahu_store_credit' ) ) {
			echo '<p>' . esc_html__( 'This coupon is not marked as store credit.', 'dahu-store-credit' ) . '</p>';
			return;
		}

		if ( empty( $history ) || ! is_array( $history ) ) {
			echo '<p>' . esc_html__( 'No history yet.', 'dahu-store-credit' ) . '</p>';
			return;
		}

		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'dahu-store-credit' ); ?></th>
					<th><?php esc_html_e( 'Order', 'dahu-store-credit' ); ?></th>
					<th><?php esc_html_e( 'Amount used', 'dahu-store-credit' ); ?></th>
					<th><?php esc_html_e( 'New balance', 'dahu-store-credit' ); ?></th>
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
