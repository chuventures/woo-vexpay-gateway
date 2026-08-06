<?php
/**
 * Blocks payment method type.
 *
 * @package VEXPay_Gateway
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers the VEXPay payment method for Cart & Checkout Blocks.
 */
final class VEXPay_Blocks_Payment_Method extends AbstractPaymentMethodType {

	/**
	 * Name / gateway id.
	 *
	 * @var string
	 */
	protected $name = 'vexpay';

	/**
	 * Gateway settings.
	 *
	 * @var array
	 */
	private $gateway_settings = array();

	/**
	 * Initialize.
	 */
	public function initialize(): void {
		$this->settings         = get_option( 'woocommerce_vexpay_settings', array() );
		$this->gateway_settings = $this->settings;
	}

	/**
	 * Active?
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return isset( $this->gateway_settings['enabled'] ) && 'yes' === $this->gateway_settings['enabled'];
	}

	/**
	 * Script handles.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		$handle = 'vexpay-blocks';
		$asset  = VEXPAY_GATEWAY_PATH . 'assets/js/blocks.asset.php';
		$deps   = array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' );
		$ver    = VEXPAY_GATEWAY_VERSION;

		if ( file_exists( $asset ) ) {
			$asset_data = include $asset;
			if ( is_array( $asset_data ) ) {
				$deps = $asset_data['dependencies'] ?? $deps;
				$ver  = $asset_data['version'] ?? $ver;
			}
		}

		wp_register_script(
			$handle,
			VEXPAY_GATEWAY_URL . 'assets/js/blocks.js',
			$deps,
			$ver,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( $handle, 'woo-vexpay-gateway', VEXPAY_GATEWAY_PATH . 'languages' );
		}

		return array( $handle );
	}

	/**
	 * Data passed to JS.
	 *
	 * @return array
	 */
	public function get_payment_method_data(): array {
		$banks = array();
		$title = $this->gateway_settings['title'] ?? __( 'VEXPay', 'woo-vexpay-gateway' );
		$desc  = $this->gateway_settings['description'] ?? '';

		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( ! empty( $gateways['vexpay'] ) && $gateways['vexpay'] instanceof VEXPay_Gateway ) {
			/** @var VEXPay_Gateway $gw */
			$gw     = $gateways['vexpay'];
			$result = $gw->get_api_client()->get_banks();
			if ( ! is_wp_error( $result ) ) {
				$list = isset( $result['banks'] ) && is_array( $result['banks'] ) ? $result['banks'] : $result;
				if ( is_array( $list ) ) {
					foreach ( $list as $bank ) {
						if ( ! is_array( $bank ) || empty( $bank['code'] ) ) {
							continue;
						}
						$banks[] = array(
							'code' => (string) $bank['code'],
							'name' => isset( $bank['name'] ) ? (string) $bank['name'] : (string) $bank['code'],
						);
					}
				}
			}
		}

		return array(
			'title'       => $title,
			'description' => $desc,
			'supports'    => array( 'products' ),
			'testmode'    => isset( $this->gateway_settings['testmode'] ) && 'yes' === $this->gateway_settings['testmode'],
			'banks'       => $banks,
		);
	}
}
