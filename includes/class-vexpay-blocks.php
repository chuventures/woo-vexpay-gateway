<?php
/**
 * WooCommerce Blocks checkout integration.
 *
 * @package VEXPay_Gateway
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Blocks bootstrap.
 */
class VEXPay_Blocks {

	/**
	 * Register.
	 *
	 * Plugin boot is on plugins_loaded priority 20; WooCommerce may have already
	 * fired woocommerce_blocks_loaded by then — call immediately if so.
	 */
	public static function init(): void {
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::on_blocks_loaded();
			return;
		}

		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'on_blocks_loaded' ) );
	}

	/**
	 * After blocks package loaded.
	 */
	public static function on_blocks_loaded(): void {
		if ( ! class_exists( AbstractPaymentMethodType::class ) ) {
			return;
		}

		require_once VEXPAY_GATEWAY_PATH . 'includes/class-vexpay-blocks-payment-method.php';

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				$registry->register( new VEXPay_Blocks_Payment_Method() );
			}
		);
	}
}
