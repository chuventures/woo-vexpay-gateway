<?php
/**
 * WooCommerce payment gateway — VEXPay USDT (hosted checkout).
 *
 * @package VEXPay_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * USDT gateway. Like VPOS, USDT is a capability of the same VEXPay account, so
 * this gateway reuses the primary `vexpay` gateway's API key and Sandbox toggle.
 *
 * Flow: process_payment() creates a VEXPay checkout session limited to USDT
 * (reference = `wc_order_<id>`) and redirects the buyer to VEXPay's hosted page,
 * which shows the deposit address. Settlement arrives as a `payment.completed`
 * webhook whose `checkoutSession.reference` identifies the order; the thank-you
 * page also re-reads the session in case the webhook was missed.
 */
class VEXPay_Gateway_USDT extends WC_Payment_Gateway {

	/** Checkout session id (cs_…) for the order's current USDT attempt. */
	public const META_SESSION_ID = '_vexpay_checkout_session_id';

	/** On-chain transfers can take a while to confirm; keep the hosted session open this long. */
	private const SESSION_TTL_MINUTES = 60;

	/** Cache USDT availability for the account so checkout doesn't call VEXPay on every render. */
	private const AVAILABILITY_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'vexpay_usdt';
		$this->method_title       = __( 'VEXPay — USDT', 'vexpay-gateway-for-woocommerce' );
		$this->method_description = __( 'Accept USDT through a VEXPay hosted checkout. Uses the same API key as VEXPay Débito inmediato. USDT must be enabled on your VEXPay account.', 'vexpay-gateway-for-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->icon               = VEXPAY_GATEWAY_URL . 'assets/images/vexpay-logo.svg';

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'USDT (VEXPay)', 'vexpay-gateway-for-woocommerce' ) );
		$this->description = $this->get_option( 'description', __( 'Paga con USDT. Te mostraremos la dirección de depósito en la red que elijas.', 'vexpay-gateway-for-woocommerce' ) );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'sync_order_from_session' ) );
	}

	/**
	 * Admin fields. No API key fields here — shared with the primary gateway.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable/Disable', 'vexpay-gateway-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable VEXPay USDT', 'vexpay-gateway-for-woocommerce' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Title', 'vexpay-gateway-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method title at checkout.', 'vexpay-gateway-for-woocommerce' ),
				'default'     => __( 'USDT (VEXPay)', 'vexpay-gateway-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'       => __( 'Description', 'vexpay-gateway-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the payment method at checkout.', 'vexpay-gateway-for-woocommerce' ),
				'default'     => __( 'Paga con USDT. Te mostraremos la dirección de depósito en la red que elijas.', 'vexpay-gateway-for-woocommerce' ),
			),
			'shared_api_key' => array(
				'type'        => 'title',
				'title'       => __( 'API key', 'vexpay-gateway-for-woocommerce' ),
				'description' => __( 'This gateway shares the API key and Sandbox toggle configured under WooCommerce → Settings → Payments → VEXPay (Débito inmediato). USDT sales settle to your VEXPay USDT balance, not to VES.', 'vexpay-gateway-for-woocommerce' ),
			),
		);
	}

	/**
	 * Settings saved on the primary `vexpay` gateway (API key, sandbox toggle).
	 *
	 * @return array
	 */
	private function shared_settings(): array {
		$settings = get_option( 'woocommerce_vexpay_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Whether the Sandbox toggle is on (shared with the primary gateway).
	 */
	public function is_sandbox_toggle_on(): bool {
		$settings = $this->shared_settings();
		return ! isset( $settings['testmode'] ) || 'yes' === $settings['testmode'];
	}

	/**
	 * Active API key (shared with the primary gateway).
	 *
	 * @return string
	 */
	public function get_active_api_key(): string {
		$settings = $this->shared_settings();
		$key      = $this->is_sandbox_toggle_on()
			? ( $settings['test_api_key'] ?? '' )
			: ( $settings['live_api_key'] ?? '' );
		return (string) $key;
	}

	/**
	 * API client for the shared settings.
	 *
	 * @return VEXPay_API_Client
	 */
	public function get_api_client(): VEXPay_API_Client {
		return new VEXPay_API_Client( VEXPAY_GATEWAY_API_BASE_URL, $this->get_active_api_key() );
	}

	/**
	 * Offer USDT only when the key is set and the VEXPay account can receive USDT.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( ! parent::is_available() ) {
			return false;
		}
		$key = $this->get_active_api_key();
		if ( '' === $key ) {
			return false;
		}
		return $this->account_accepts_usdt( $key );
	}

	/**
	 * Cached `GET /v1/crypto/networks` check (403 when USDT is not enabled on the account).
	 *
	 * @param string $key Active API key (the cache is per key, so sandbox/live don't mix).
	 * @return bool
	 */
	private function account_accepts_usdt( string $key ): bool {
		$cache_key = 'vexpay_usdt_ok_' . md5( $key );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return 'yes' === $cached;
		}

		$networks = $this->get_api_client()->list_crypto_networks();
		$ok       = false;
		if ( ! is_wp_error( $networks ) && is_array( $networks ) ) {
			foreach ( $networks as $network ) {
				if ( is_array( $network ) && ! empty( $network['receiveEnabled'] ) ) {
					$ok = true;
					break;
				}
			}
		}
		set_transient( $cache_key, $ok ? 'yes' : 'no', self::AVAILABILITY_TTL );
		return $ok;
	}

	/**
	 * Classic checkout: no fields, just a short explanation.
	 */
	public function payment_fields(): void {
		echo '<div class="vexpay-flow vexpay-checkout-panel">';
		echo '<div class="vexpay-checkout-brand">';
		echo '<img class="vexpay-checkout-brand__logo" src="' . esc_url( VEXPAY_GATEWAY_URL . 'assets/images/vexpay-logo.svg' ) . '" alt="VEXPay" width="72" height="38" decoding="async" />';
		echo '<span class="vexpay-checkout-brand__tag">' . esc_html__( 'USDT', 'vexpay-gateway-for-woocommerce' ) . '</span>';
		echo '</div>';

		if ( $this->is_sandbox_toggle_on() ) {
			echo '<p class="vexpay-test-mode"><strong>' . esc_html__( 'SANDBOX', 'vexpay-gateway-for-woocommerce' ) . '</strong> — ';
			echo esc_html__( 'No real funds move here.', 'vexpay-gateway-for-woocommerce' );
			echo '</p>';
		}

		if ( $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}

		echo '<p class="vexpay-checkout-secure">';
		echo '<span class="vexpay-checkout-secure__lock" aria-hidden="true"></span>';
		echo esc_html__( 'Secured by VEXPay', 'vexpay-gateway-for-woocommerce' );
		echo '</p>';
		echo '</div>';
	}

	/**
	 * Create (or reuse) the hosted USDT checkout and redirect the buyer to it.
	 *
	 * @param int $order_id Order ID.
	 * @return array{result:string,redirect?:string}
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'vexpay-gateway-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$client = $this->get_api_client();

		// Reuse an open session so a retry never gives the buyer a second address for the same order.
		$existing_id = (string) $order->get_meta( self::META_SESSION_ID );
		if ( '' !== $existing_id ) {
			$existing = $client->get_checkout_session( $existing_id );
			if ( ! is_wp_error( $existing ) && in_array( $existing['status'] ?? '', array( 'open', 'processing' ), true ) && ! empty( $existing['url'] ) ) {
				return array(
					'result'   => 'success',
					'redirect' => (string) $existing['url'],
				);
			}
		}

		$external_ref = VEXPay_Helpers::external_ref_for_order( (int) $order_id );
		$session      = $client->create_checkout_session(
			array(
				'amountUsd'        => VEXPay_Helpers::order_usd_amount( $order ),
				'reference'        => $external_ref,
				/* translators: %s: order number */
				'description'      => sprintf( __( 'Order #%s', 'vexpay-gateway-for-woocommerce' ), $order->get_order_number() ),
				'methods'          => array( 'usdt' ),
				'successUrl'       => $this->get_return_url( $order ),
				'cancelUrl'        => $order->get_checkout_payment_url(),
				'expiresInMinutes' => self::SESSION_TTL_MINUTES,
				'metadata'         => array( 'orderId' => (string) $order_id ),
			)
		);

		if ( is_wp_error( $session ) || empty( $session['id'] ) || empty( $session['url'] ) ) {
			$message = is_wp_error( $session ) ? $session->get_error_message() : 'invalid session response';
			VEXPay_Logger::error( sprintf( 'USDT checkout session failed for order %d: %s', $order_id, $message ) );
			wc_add_notice( __( 'USDT payments are not available right now. Please choose another payment method.', 'vexpay-gateway-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( VEXPay_Helpers::META_EXTERNAL_REF, $external_ref );
		$order->update_meta_data( self::META_SESSION_ID, (string) $session['id'] );
		$order->update_meta_data( VEXPay_Helpers::META_STATUS, 'PENDING' );
		$order->update_status( 'pending', __( 'Awaiting USDT payment (VEXPay hosted checkout).', 'vexpay-gateway-for-woocommerce' ) );
		$order->save();

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => (string) $session['url'],
		);
	}

	/**
	 * Thank-you page safety net: re-read the session and apply its payment if the webhook hasn't.
	 *
	 * @param int $order_id Order ID.
	 */
	public function sync_order_from_session( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->is_paid() ) {
			return;
		}
		$session_id = (string) $order->get_meta( self::META_SESSION_ID );
		if ( '' === $session_id ) {
			return;
		}

		$client  = $this->get_api_client();
		$session = $client->get_checkout_session( $session_id );
		if ( is_wp_error( $session ) || empty( $session['paymentId'] ) ) {
			return;
		}
		$payment = $client->get_payment( (string) $session['paymentId'] );
		if ( is_wp_error( $payment ) ) {
			return;
		}
		$this->apply_payment_result( $order, $payment );
	}

	/**
	 * Apply a USDT payment (webhook `data` or GET /v1/payments/:id) to the order.
	 *
	 * An underpaid transfer is COMPLETED at VEXPay (the funds are credited to the store's
	 * USDT balance) but must not complete the order: it goes on hold for the merchant.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $result Payment.
	 */
	public function apply_payment_result( WC_Order $order, array $result ): void {
		$status    = isset( $result['status'] ) ? strtoupper( (string) $result['status'] ) : '';
		$underpaid = ! empty( $result['underpaid'] ) || 'UNDERPAID' === ( $result['failureCode'] ?? '' );
		$amount    = isset( $result['amountUsdt'] ) ? (string) $result['amountUsdt'] : '';
		$network   = isset( $result['network'] ) ? (string) $result['network'] : '';

		if ( ! empty( $result['paymentId'] ) ) {
			$order->update_meta_data( VEXPay_Helpers::META_PAYMENT_ID, (string) $result['paymentId'] );
		}
		$order->update_meta_data( VEXPay_Helpers::META_STATUS, $underpaid && 'COMPLETED' === $status ? 'UNDERPAID' : $status );

		$tx_hash = ! empty( $result['txHashes'] ) && is_array( $result['txHashes'] ) ? (string) $result['txHashes'][0] : '';
		if ( '' !== $tx_hash ) {
			$order->set_transaction_id( $tx_hash );
		}

		$action = VEXPay_Helpers::map_payment_status( $status );

		if ( 'complete' === $action && $underpaid ) {
			if ( ! $order->has_status( 'on-hold' ) ) {
				$order->update_status(
					'on-hold',
					sprintf(
						/* translators: 1: USDT received 2: network */
						__( 'VEXPay USDT: less than the order total was received (%1$s USDT on %2$s). The funds are in your VEXPay USDT balance — settle the difference with the buyer before fulfilling.', 'vexpay-gateway-for-woocommerce' ),
						$amount,
						$network
					)
				);
			}
		} elseif ( 'complete' === $action ) {
			if ( ! $order->is_paid() ) {
				$order->payment_complete( '' !== $tx_hash ? $tx_hash : (string) ( $result['paymentId'] ?? '' ) );
				$order->add_order_note(
					sprintf(
						/* translators: 1: USDT received 2: network 3: payment id */
						__( 'VEXPay USDT payment completed: %1$s USDT on %2$s (%3$s).', 'vexpay-gateway-for-woocommerce' ),
						$amount,
						$network,
						(string) ( $result['paymentId'] ?? '' )
					)
				);
			}
		} elseif ( 'fail' === $action && ! $order->is_paid() ) {
			$reason = ! empty( $result['failureCode'] ) ? (string) $result['failureCode'] : $status;
			$order->update_status( 'failed', 'VEXPay USDT: ' . $reason );
		}

		$order->save();
	}

	/**
	 * Process refund — USDT refunds are sent from the VEXPay USDT balance, not automatically.
	 *
	 * @param int        $order_id Order ID.
	 * @param float|null $amount   Amount.
	 * @param string     $reason   Reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		unset( $order_id, $amount, $reason );
		return new WP_Error(
			'vexpay_usdt_refund',
			__( 'Automatic refunds are not available for USDT. Send the refund to the buyer from your VEXPay USDT balance.', 'vexpay-gateway-for-woocommerce' )
		);
	}
}
