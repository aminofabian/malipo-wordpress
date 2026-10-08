<?php
/**
 * Plugin Name:       KioskPay — M-Pesa Payments
 * Plugin URI:        https://kioskpay.co.ke/wordpress/
 * Description:       Accept M-Pesa payments on your WordPress shop through KioskPay. Ships a WooCommerce gateway and a [malipo_pay] shortcode. Sign up at kioskpay.co.ke, copy your keys, no Daraja app.
 * Version:           0.1.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            KioskPay
 * Author URI:        https://kioskpay.co.ke
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       malipo-payments
 * Domain Path:       /languages
 * WC requires at least: 6.0
 * WC tested up to:   9.9
 *
 * @package Malipo
 */

defined( 'ABSPATH' ) || exit;

define( 'MALIPO_VERSION', '0.1.3' );
define( 'MALIPO_FILE', __FILE__ );
define( 'MALIPO_DIR', plugin_dir_path( __FILE__ ) );
define( 'MALIPO_URL', plugin_dir_url( __FILE__ ) );

require_once MALIPO_DIR . 'includes/class-malipo-util.php';
require_once MALIPO_DIR . 'includes/class-malipo-api.php';
require_once MALIPO_DIR . 'includes/class-malipo-settings.php';
require_once MALIPO_DIR . 'includes/class-malipo-payments.php';
require_once MALIPO_DIR . 'includes/class-malipo-rest.php';
require_once MALIPO_DIR . 'includes/class-malipo-shortcode.php';
require_once MALIPO_DIR . 'includes/class-malipo-updater.php';

/**
 * Boot the plugin once every plugin is loaded, so WooCommerce can be detected.
 */
function malipo_boot() {
	load_plugin_textdomain( 'malipo-payments', false, dirname( plugin_basename( MALIPO_FILE ) ) . '/languages' );

	Malipo_Settings::init();
	Malipo_Payments::init();
	Malipo_REST::init();
	Malipo_Shortcode::init();
	Malipo_Updater::init();

	if ( class_exists( 'WC_Payment_Gateway' ) ) {
		require_once MALIPO_DIR . 'includes/class-malipo-gateway.php';
		Malipo_Gateway::init();
	}
}
add_action( 'plugins_loaded', 'malipo_boot' );

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				MALIPO_FILE,
				true
			);
		}
	}
);

/**
 * Activation: register the payment record type, then flush rewrites.
 */
function malipo_activate() {
	Malipo_Payments::register_post_type();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'malipo_activate' );

/**
 * Deactivation: drop our rewrite rules.
 */
function malipo_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'malipo_deactivate' );
