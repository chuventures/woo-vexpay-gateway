<?php
/**
 * Plugin orchestrator.
 *
 * @package VEXPay_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class VEXPay_Plugin {

	/**
	 * Singleton.
	 *
	 * @var VEXPay_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Instance.
	 *
	 * @return VEXPay_Plugin
	 */
	public static function instance(): VEXPay_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook registrations.
	 */
	public function init(): void {
		load_plugin_textdomain( 'woo-vexpay-gateway', false, dirname( plugin_basename( VEXPAY_GATEWAY_FILE ) ) . '/languages' );

		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_assets' ) );
		// Register on the plugin, not the gateway constructor — WC may not instantiate
		// payment gateways during admin-ajax.php, which would leave the action unregistered (HTTP 400 / "0").
		add_action( 'wp_ajax_vexpay_test_connection', array( $this, 'ajax_test_connection' ) );

		VEXPay_Webhook::init();
		VEXPay_Blocks::init();
	}

	/**
	 * Register gateway with WooCommerce.
	 *
	 * @param array $gateways Gateways.
	 * @return array
	 */
	public function register_gateway( array $gateways ): array {
		$gateways[] = 'VEXPay_Gateway';
		return $gateways;
	}

	/**
	 * AJAX: Test connection — load the gateway then delegate.
	 */
	public function ajax_test_connection(): void {
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( empty( $gateways['vexpay'] ) || ! $gateways['vexpay'] instanceof VEXPay_Gateway ) {
			wp_send_json_error(
				array( 'message' => __( 'VEXPay gateway is not available.', 'woo-vexpay-gateway' ) )
			);
		}

		$gateways['vexpay']->ajax_test_connection();
	}

	/**
	 * Front-end CSS/JS for checkout + OTP page.
	 */
	public function enqueue_front_assets(): void {
		if ( ! is_checkout() && ! is_wc_endpoint_url( 'order-pay' ) && ! is_order_received_page() ) {
			return;
		}

		wp_enqueue_style(
			'vexpay-gateway',
			VEXPAY_GATEWAY_URL . 'assets/css/checkout.css',
			array(),
			VEXPAY_GATEWAY_VERSION
		);

		wp_enqueue_script(
			'vexpay-gateway',
			VEXPAY_GATEWAY_URL . 'assets/js/checkout.js',
			array( 'jquery' ),
			VEXPAY_GATEWAY_VERSION,
			true
		);
	}
}
