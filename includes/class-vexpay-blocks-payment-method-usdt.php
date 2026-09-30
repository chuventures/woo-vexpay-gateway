<?php
/**
 * WooCommerce Blocks integration for VEXPay USDT.
 *
 * @package VEXPay_Gateway
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Blocks payment method type for the USDT gateway (no fields — redirects to VEXPay).
 */
final class VEXPay_Blocks_Payment_Method_USDT extends AbstractPaymentMethodType {

	/**
	 * Payment method name (matches the gateway id).
	 *
	 * @var string
	 */
	protected $name = 'vexpay_usdt';

	/**
	 * Load settings.
	 */
	public function initialize(): void {
		$this->settings = get_option( 'woocommerce_vexpay_usdt_settings', array() );
	}

	/**
	 * Active when enabled and the gateway is available (key set, USDT enabled on the account).
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		if ( ! isset( $this->settings['enabled'] ) || 'yes' !== $this->settings['enabled'] ) {
			return false;
		}
		$gateway = $this->gateway();
		return $gateway && $gateway->is_available();
	}

	/**
	 * Script handles.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		$handle = 'vexpay-usdt-blocks';
		wp_register_script(
			$handle,
			VEXPAY_GATEWAY_URL . 'assets/js/blocks-usdt.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			VEXPAY_GATEWAY_VERSION,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( $handle, 'vexpay-gateway-for-woocommerce', VEXPAY_GATEWAY_PATH . 'languages' );
		}

		return array( $handle );
	}

	/**
	 * Data passed to the script.
	 *
	 * @return array
	 */
	public function get_payment_method_data(): array {
		$gateway = $this->gateway();
		return array(
			'title'       => $gateway ? $gateway->get_title() : ( $this->settings['title'] ?? __( 'USDT (VEXPay)', 'vexpay-gateway-for-woocommerce' ) ),
			'description' => $gateway ? (string) $gateway->get_description() : ( $this->settings['description'] ?? '' ),
			'supports'    => array( 'products' ),
			'testmode'    => $gateway ? $gateway->is_sandbox_toggle_on() : true,
			'icon'        => VEXPAY_GATEWAY_URL . 'assets/images/vexpay-logo.svg',
		);
	}

	/**
	 * Registered USDT gateway instance.
	 *
	 * @return VEXPay_Gateway_USDT|null
	 */
	private function gateway(): ?VEXPay_Gateway_USDT {
		$gateways = WC()->payment_gateways()->payment_gateways();
		return ! empty( $gateways['vexpay_usdt'] ) && $gateways['vexpay_usdt'] instanceof VEXPay_Gateway_USDT
			? $gateways['vexpay_usdt']
			: null;
	}
}
