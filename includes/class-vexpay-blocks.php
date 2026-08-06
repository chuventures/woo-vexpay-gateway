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
	 */
	public static function init(): void {
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
