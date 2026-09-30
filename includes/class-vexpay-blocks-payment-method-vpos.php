<?php
/**
 * Blocks payment method type — VEXPay Tarjeta (VPOS).
 *
 * @package VEXPay_Gateway
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers the VEXPay Tarjeta payment method for Cart & Checkout Blocks.
 */
final class VEXPay_Blocks_Payment_Method_VPOS extends AbstractPaymentMethodType {

	/**
	 * Name / gateway id.
	 *
	 * @var string
	 */
	protected $name = 'vexpay_vpos';

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
		$this->settings         = get_option( 'woocommerce_vexpay_vpos_settings', array() );
		$this->gateway_settings = $this->settings;
	}

	/**
	 * Active?
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		if ( ! isset( $this->gateway_settings['enabled'] ) || 'yes' !== $this->gateway_settings['enabled'] ) {
			return false;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return ! empty( $gateways['vexpay_vpos'] ) && $gateways['vexpay_vpos'] instanceof VEXPay_Gateway_VPOS
			&& $gateways['vexpay_vpos']->is_available();
	}

	/**
	 * Script handles.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		$handle = 'vexpay-vpos-blocks';
		$asset  = VEXPAY_GATEWAY_PATH . 'assets/js/blocks-vpos.asset.php';
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
			VEXPAY_GATEWAY_URL . 'assets/js/blocks-vpos.js',
			$deps,
			$ver,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( $handle, 'vexpay-gateway-for-woocommerce', VEXPAY_GATEWAY_PATH . 'languages' );
		}

		return array( $handle );
	}

	/**
	 * Data passed to JS.
	 *
	 * @return array
	 */
	public function get_payment_method_data(): array {
		$title = $this->gateway_settings['title'] ?? __( 'Tarjeta (VEXPay)', 'vexpay-gateway-for-woocommerce' );
		$desc  = $this->gateway_settings['description'] ?? '';
		$quote = null;

		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( ! empty( $gateways['vexpay_vpos'] ) && $gateways['vexpay_vpos'] instanceof VEXPay_Gateway_VPOS ) {
			/**
			 * VEXPay VPOS gateway instance.
			 *
			 * @var VEXPay_Gateway_VPOS $gw
			 */
			$gw    = $gateways['vexpay_vpos'];
			$title = $gw->get_title();
			$desc  = (string) $gw->get_description();

			$show_quote = 'yes' === ( $this->gateway_settings['show_ves_quote'] ?? 'yes' );
			if ( $show_quote && function_exists( 'WC' ) && WC()->cart ) {
				$usd    = round( (float) WC()->cart->get_total( 'edit' ), 2 );
				$result = $gw->get_api_client()->get_quote( $usd );
				if ( ! is_wp_error( $result ) && isset( $result['vesAmount'], $result['bcvRate'] ) ) {
					$quote = array(
						'usd'       => $usd,
						'vesAmount' => (float) $result['vesAmount'],
						'bcvRate'   => (float) $result['bcvRate'],
					);
				}
			}
		}

		return array(
			'title'        => $title,
			'description'  => $desc,
			'supports'     => array( 'products' ),
			'testmode'     => isset( $gateways['vexpay_vpos'] ) && $gateways['vexpay_vpos'] instanceof VEXPay_Gateway_VPOS
				? $gateways['vexpay_vpos']->is_sandbox_toggle_on()
				: true,
			'icon'         => VEXPAY_GATEWAY_URL . 'assets/images/vexpay-logo.svg',
			'quote'        => $quote,
			'idTypes'      => VEXPay_Helpers::debtor_id_types(),
			'cardBrands'   => VEXPay_Helpers::card_brands(),
			'accountTypes' => VEXPay_Helpers::card_account_types(),
		);
	}
}
