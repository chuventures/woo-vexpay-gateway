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
		// payment gateways during admin-ajax.php / early wc-api requests, which would leave
		// the action unregistered (blank response / HTTP 400 / "0").
		add_action( 'wp_ajax_vexpay_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'woocommerce_api_vexpay_otp', array( $this, 'api_otp_submit' ) );
		add_action( 'woocommerce_api_vexpay_otp_resend', array( $this, 'api_otp_resend' ) );
		add_action( 'woocommerce_api_vexpay_otp_change_account', array( $this, 'api_otp_change_account' ) );
		add_action( 'woocommerce_api_vexpay_otp_form', array( $this, 'api_otp_form' ) );
		add_action( 'woocommerce_api_vexpay_otp_back_checkout', array( $this, 'api_otp_back_checkout' ) );

		VEXPay_Webhook::init();
		VEXPay_Poller::init();
		VEXPay_Blocks::init();
	}

	/**
	 * Load the VEXPay gateway instance (instantiates payment gateways if needed).
	 */
	private function gateway(): ?VEXPay_Gateway {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( empty( $gateways['vexpay'] ) || ! $gateways['vexpay'] instanceof VEXPay_Gateway ) {
			return null;
		}
		return $gateways['vexpay'];
	}

	/**
	 * wc-api: OTP execute.
	 */
	public function api_otp_submit(): void {
		$gateway = $this->gateway();
		if ( ! $gateway ) {
			wp_die( esc_html__( 'VEXPay gateway is not available.', 'woo-vexpay-gateway' ), 503 );
		}
		$gateway->handle_otp_submit();
	}

	/**
	 * wc-api: request / resend débito OTP.
	 */
	public function api_otp_resend(): void {
		$gateway = $this->gateway();
		if ( ! $gateway ) {
			wp_die( esc_html__( 'VEXPay gateway is not available.', 'woo-vexpay-gateway' ), 503 );
		}
		$gateway->handle_otp_resend();
	}

	/**
	 * wc-api: change paying account on OTP step.
	 */
	public function api_otp_change_account(): void {
		$gateway = $this->gateway();
		if ( ! $gateway ) {
			wp_die( esc_html__( 'VEXPay gateway is not available.', 'woo-vexpay-gateway' ), 503 );
		}
		$gateway->handle_otp_change_account();
	}

	/**
	 * wc-api: dedicated OTP page.
	 */
	public function api_otp_form(): void {
		$gateway = $this->gateway();
		if ( ! $gateway ) {
			wp_die( esc_html__( 'VEXPay gateway is not available.', 'woo-vexpay-gateway' ), 503 );
		}
		$gateway->render_otp_form_page();
	}

	/**
	 * wc-api: abandon OTP and return to checkout (restore cart).
	 */
	public function api_otp_back_checkout(): void {
		$gateway = $this->gateway();
		if ( ! $gateway ) {
			wp_die( esc_html__( 'VEXPay gateway is not available.', 'woo-vexpay-gateway' ), 503 );
		}
		$gateway->handle_otp_back_checkout();
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
			'vexpay-fonts',
			'https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=Outfit:wght@500;600;700&display=swap',
			array(),
			null
		);

		wp_enqueue_style(
			'vexpay-gateway',
			VEXPAY_GATEWAY_URL . 'assets/css/checkout.css',
			array( 'vexpay-fonts' ),
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
