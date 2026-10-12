<?php
/**
 * Plugin Name:       Dahu Store Credit
 * Plugin URI:        https://dahu-concept.fr
 * Description:       Carries the unused balance of a WooCommerce fixed-cart coupon forward instead of consuming it whole, turning any coupon into real store credit.
 * Version:           1.0.4
 * Author:            Hugo Vial-Jaime
 * Author URI:        mailto:contact@dahu-concept.fr
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       dahu-store-credit
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.4
 *
 * @package DahuStoreCredit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DAHU_STORE_CREDIT_VERSION', '1.0.4' );
define( 'DAHU_STORE_CREDIT_FILE', __FILE__ );
define( 'DAHU_STORE_CREDIT_PATH', plugin_dir_path( __FILE__ ) );
define( 'DAHU_STORE_CREDIT_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'dahu-store-credit', false, dirname( plugin_basename( DAHU_STORE_CREDIT_FILE ) ) . '/languages' );
	}
);

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DAHU_STORE_CREDIT_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', 'dahu_store_credit_init' );

function dahu_store_credit_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Dahu Store Credit requires WooCommerce.', 'dahu-store-credit' ) . '</p></div>';
			}
		);
		return;
	}

	require_once DAHU_STORE_CREDIT_PATH . 'includes/class-dahu-store-credit-core.php';
	require_once DAHU_STORE_CREDIT_PATH . 'includes/class-dahu-store-credit-admin.php';

	Dahu_Store_Credit_Core::instance();
	Dahu_Store_Credit_Admin::instance();
}

add_filter(
	'plugin_action_links_' . plugin_basename( DAHU_STORE_CREDIT_FILE ),
	static function ( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=dahu-store-credit' ) ),
			esc_html__( 'Settings', 'dahu-store-credit' )
		);
		array_unshift( $links, $settings );
		return $links;
	}
);

add_filter(
	'plugin_row_meta',
	function ( $links, $file ) {
		if ( plugin_basename( DAHU_STORE_CREDIT_FILE ) === $file ) {
			$header = get_file_data( DAHU_STORE_CREDIT_FILE, array( 'Version' => 'Version' ) );
			$links  = array(
				sprintf(
					/* translators: %s: plugin version number. */
					esc_html__( 'Version %s', 'dahu-store-credit' ),
					esc_html( $header['Version'] )
				),
				sprintf(
					'<a href="%s" target="_blank" rel="noopener">%s</a>',
					esc_url( 'https://dahu-concept.fr' ),
					esc_html__( 'By Hugo Vial-Jaime — Dahu-Concept', 'dahu-store-credit' )
				),
				sprintf(
					'<a href="%s" target="_blank" rel="noopener">%s</a>',
					esc_url( 'https://dahu-concept.fr' ),
					esc_html__( 'Plugin website', 'dahu-store-credit' )
				),
			);
		}
		return $links;
	},
	10,
	2
);
