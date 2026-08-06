<?php
/**
 * Plugin Name:       VEXPay Gateway for WooCommerce
 * Plugin URI:        https://github.com/chuventures/woo-vexpay-gateway
 * Description:       Accept Venezuela C2P (Pago Móvil C2P) payments via VEXPay.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            VEXPay
 * Author URI:        https://banking.chuventures.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woo-vexpay-gateway
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   9.8
 *
 * @package VEXPay_Gateway
 */

defined( 'ABSPATH' ) || exit;

define( 'VEXPAY_GATEWAY_VERSION', '1.0.0' );
define( 'VEXPAY_GATEWAY_FILE', __FILE__ );
define( 'VEXPAY_GATEWAY_PATH', plugin_dir_path( __FILE__ ) );
define( 'VEXPAY_GATEWAY_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare WooCommerce feature compatibility.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', VEXPAY_GATEWAY_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', VEXPAY_GATEWAY_FILE, true );
		}
	}
);

/**
 * Bootstrap after plugins load.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'VEXPay Gateway requires WooCommerce to be installed and active.', 'woo-vexpay-gateway' );
					echo '</p></div>';
				}
			);
			return;
		}

		require_once VEXPAY_GATEWAY_PATH . 'includes/class-vexpay-logger.php';
		require_once VEXPAY_GATEWAY_PATH . 'includes/class-vexpay-helpers.php';
		require_once VEXPAY_GATEWAY_PATH . 'includes/class-vexpay-api-client.php';
		require_once VEXPAY_GATEWAY_PATH . 'includes/class-vexpay-webhook.php';
		require_once VEXPAY_GATEWAY_PATH . 'includes/class-vexpay-gateway.php';
		require_once VEXPAY_GATEWAY_PATH . 'includes/class-vexpay-blocks.php';
		require_once VEXPAY_GATEWAY_PATH . 'includes/class-vexpay-plugin.php';

		VEXPay_Plugin::instance()->init();
	},
	20
);
