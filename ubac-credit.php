<?php
/**
 * Plugin Name:       Ubac Store Credit
 * Description:       Carries the unused balance of a WooCommerce fixed-cart coupon forward instead of consuming it whole, turning any coupon into real store credit.
 * Version:           1.2.0
 * Author:            Hugo Vial-Jaime
 * Author URI:        https://dahu-concept.fr
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       ubac-credit
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Requires Plugins: woocommerce
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   11.2
 *
 * @package UbacCredit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UBAC_CREDIT_VERSION', '1.2.0' );
define( 'UBAC_CREDIT_FILE', __FILE__ );
define( 'UBAC_CREDIT_PATH', plugin_dir_path( __FILE__ ) );
define( 'UBAC_CREDIT_URL', plugin_dir_url( __FILE__ ) );

/* -------------------------------------------------------------------------
 * Translations
 *
 * There is deliberately no load_plugin_textdomain() call here. Since
 * WordPress 4.6 the translations of a plugin hosted on WordPress.org are
 * loaded on demand, and the directory's review asks that the call be removed
 * rather than kept: this plugin requires 6.0, so there is no older WordPress
 * to cover. Translations come from translate.wordpress.org.
 * ---------------------------------------------------------------------- */

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', UBAC_CREDIT_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', 'ubac_credit_init' );

function ubac_credit_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Ubac Store Credit requires WooCommerce.', 'ubac-credit' ) . '</p></div>';
			}
		);
		return;
	}

	require_once UBAC_CREDIT_PATH . 'includes/class-ubac-credit-core.php';
	require_once UBAC_CREDIT_PATH . 'includes/class-ubac-credit-admin.php';

	Ubac_Credit_Core::instance();
	Ubac_Credit_Admin::instance();
}

add_filter(
	'plugin_action_links_' . plugin_basename( UBAC_CREDIT_FILE ),
	static function ( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=ubac-credit' ) ),
			esc_html__( 'Settings', 'ubac-credit' )
		);
		array_unshift( $links, $settings );
		return $links;
	}
);

add_filter(
	'plugin_row_meta',
	function ( $links, $file ) {
		if ( plugin_basename( UBAC_CREDIT_FILE ) === $file ) {
			$header = get_file_data( UBAC_CREDIT_FILE, array( 'Version' => 'Version' ) );
			$links  = array(
				sprintf(
					/* translators: %s: plugin version number. */
					esc_html__( 'Version %s', 'ubac-credit' ),
					esc_html( $header['Version'] )
				),
				sprintf(
					'<a href="%s" target="_blank" rel="noopener">%s</a>',
					esc_url( 'https://dahu-concept.fr' ),
					esc_html__( 'By Hugo Vial-Jaime — Dahu-Concept', 'ubac-credit' )
				),
				sprintf(
					'<a href="%s" target="_blank" rel="noopener">%s</a>',
					esc_url( 'https://dahu-concept.fr' ),
					esc_html__( 'Plugin website', 'ubac-credit' )
				),
			);
		}
		return $links;
	},
	10,
	2
);

/*
 * The welcome screen, shown once just after activation.
 *
 * Registered here rather than inside the admin include, because the
 * activation flag has to be set whatever else is loaded.
 */
require_once UBAC_CREDIT_PATH . 'includes/ubac-welcome.php';

/*
 * The flag, not a redirect: an activation hook that redirects breaks bulk
 * activation and WP-CLI, which activate several plugins in one request. The
 * welcome screen reads the flag on the next page load, where a redirect is
 * safe, and clears it when several plugins were activated together.
 */
register_activation_hook(
	__FILE__,
	static function () {
		Ubac_Welcome::mark_activated( 'ubac-credit' );
	}
);

add_action(
	'init',
	static function () {
		Ubac_Welcome::register(
			array(
				'slug'       => 'ubac-credit',
				'name'       => __( 'Store credit', 'ubac-credit' ),
				'tagline'    => __( 'Credit a customer can spend at the checkout, and where it came from.', 'ubac-credit' ),
				'file'       => UBAC_CREDIT_FILE,
				'version'    => UBAC_CREDIT_VERSION,
				'textdomain' => 'ubac-credit',
				'mark'       => array(
					'view'  => '0 0 112 112',
					'paths' => array(
						'M15.0 20.0 H97.0 A9.0 9.0 0 0 1 106.0 29.0 V69.0 A9.0 9.0 0 0 1 97.0 78.0 H15.0 A9.0 9.0 0 0 1 6.0 69.0 V29.0 A9.0 9.0 0 0 1 15.0 20.0 Z',
						'M6 40 L106 40',
						'M84 94 Q46 102 28 76',
						'M20 82 L28 72 L38 79',
						'M24 58 L50 58',
					),
				),
				/*
				 * The welcome screen's words come from here: the shared file is
				 * copied byte for byte across the whole family, so it can carry
				 * no text domain of its own.
				 */
				'strings'    => array(
					'next'   => __( 'Next', 'ubac-credit' ),
					'done'   => __( 'Done', 'ubac-credit' ),
					'skip'   => __( 'Skip', 'ubac-credit' ),
					'of'     => __( 'of', 'ubac-credit' ),
					'denied' => __( 'You are not allowed to view this page.', 'ubac-credit' ),
				),
				'points'     => array(
				array(
					'title' => __( 'A balance per customer', 'ubac-credit' ),
					'text'  => __( 'Added by hand or by a refund.', 'ubac-credit' ),
				),
				array(
					'title' => __( 'Spent at the checkout', 'ubac-credit' ),
					'text'  => __( 'Applied like a payment, not a coupon.', 'ubac-credit' ),
				),
				array(
					'title' => __( 'Always traceable', 'ubac-credit' ),
					'text'  => __( 'Every movement says who made it and why.', 'ubac-credit' ),
				),
				),

				/*
				 * The one-time tour.
				 *
				 * Every selector below points at something the settings screen
				 * always renders. A step whose target is missing is skipped
				 * without a sound, so only the card is targeted, never the table
				 * inside it (rendered only once a coupon is marked).
				 */
				'tour_screen' => 'woocommerce_page_ubac-credit',
				'tour'        => array(
					array(
						'selector' => '#ubac-credit-list',
						'title'    => __( 'Coupons become a balance', 'ubac-credit' ),
						'text'     => __( 'What is left on a coupon stays available for the next order.', 'ubac-credit' ),
					),
					array(
						'selector' => 'input[name="ubac_credit_settings[enabled]"]',
						'title'    => __( 'Switch it on or off', 'ubac-credit' ),
						'text'     => __( 'When on, the remaining balance is carried forward after payment.', 'ubac-credit' ),
					),
					array(
						'selector' => 'input[name="ubac_credit_settings[extension_days]"]',
						'title'    => __( 'Extend the validity', 'ubac-credit' ),
						'text'     => __( 'Days added to the expiry date on each partial use.', 'ubac-credit' ),
					),
				),
			)
		);
	}
);
