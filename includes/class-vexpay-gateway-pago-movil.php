<?php
/**
 * WooCommerce payment gateway — VEX Pay Pago Móvil (hosted checkout).
 *
 * @package VEXPay_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pago Móvil gateway. Reuses the primary `vexpay` gateway's API key and Sandbox toggle.
 *
 * Flow: process_payment() creates a VEX Pay checkout session limited to Pago Móvil
 * (reference = `wc_order_<id>`) and redirects the buyer to the hosted page, which shows
 * where to send the Pago Móvil. The buyer may pay in several transfers: each one that
 * doesn't complete the payment sends `payment.pending` with `paidVesAmount`, and the
 * order goes On hold. `payment.completed` pays the order; an expiry with money on it
 * (`refundPending`) cancels it. The poller and the thank-you page re-read the session
 * in case a webhook was missed.
 */
class VEXPay_Gateway_Pago_Movil extends WC_Payment_Gateway {

	/** Checkout session id (cs_…) for the order's current attempt. */
	public const META_SESSION_ID = '_vexpay_checkout_session_id';

	/** Hosted session lifetime before the first transfer (VEX Pay keeps a started payment open longer). */
	private const SESSION_TTL_MINUTES = 60;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'vexpay_pago_movil';
		$this->method_title       = __( 'VEX Pay — Pago Móvil', 'vexpay-gateway-for-woocommerce' );
		$this->method_description = __( 'Accept Pago Móvil through a VEX Pay hosted checkout. The buyer can pay in several transfers. Uses the same API key as VEX Pay Débito inmediato. Pago Móvil must be enabled on your VEX Pay account.', 'vexpay-gateway-for-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->icon               = VEXPAY_GATEWAY_URL . 'assets/images/vexpay-logo.svg';

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Pago Móvil (VEX Pay)', 'vexpay-gateway-for-woocommerce' ) );
		$this->description = $this->get_option( 'description', __( 'Paga con Pago Móvil desde la app de tu banco. Te mostraremos los datos para enviarlo.', 'vexpay-gateway-for-woocommerce' ) );
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
				'label'   => __( 'Enable VEX Pay Pago Móvil', 'vexpay-gateway-for-woocommerce' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Title', 'vexpay-gateway-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method title at checkout.', 'vexpay-gateway-for-woocommerce' ),
				'default'     => __( 'Pago Móvil (VEX Pay)', 'vexpay-gateway-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'       => __( 'Description', 'vexpay-gateway-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the payment method at checkout.', 'vexpay-gateway-for-woocommerce' ),
				'default'     => __( 'Paga con Pago Móvil desde la app de tu banco. Te mostraremos los datos para enviarlo.', 'vexpay-gateway-for-woocommerce' ),
			),
			'shared_api_key' => array(
				'type'        => 'title',
				'title'       => __( 'API key', 'vexpay-gateway-for-woocommerce' ),
				'description' => __( 'This gateway shares the API key, Sandbox toggle and webhook secret configured under WooCommerce → Settings → Payments → VEXPay (Débito inmediato). If the buyer pays only part of the total, the order goes On hold until the rest arrives; if the rest never arrives, the order is cancelled and VEX Pay support refunds the buyer.', 'vexpay-gateway-for-woocommerce' ),
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
	 * Offer Pago Móvil when enabled and keyed.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( '' === $this->get_active_api_key() ) {
			return false;
		}
		// Marketplace mode: hosted sessions take no splits, so hide it for carts with comercio items.
		return ! VEXPay_Marketplace::cart_has_comercio_items();
	}

	/**
	 * Classic checkout: no fields, just a short explanation.
	 */
	public function payment_fields(): void {
		echo '<div class="vexpay-flow vexpay-checkout-panel">';
		echo '<div class="vexpay-checkout-brand">';
		echo '<img class="vexpay-checkout-brand__logo" src="' . esc_url( VEXPAY_GATEWAY_URL . 'assets/images/vexpay-logo.svg' ) . '" alt="VEX Pay" width="72" height="38" decoding="async" />';
		echo '<span class="vexpay-checkout-brand__tag">' . esc_html__( 'Pago Móvil', 'vexpay-gateway-for-woocommerce' ) . '</span>';
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
	 * Create (or reuse) the hosted Pago Móvil checkout and redirect the buyer to it.
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

		if ( VEXPay_Marketplace::order_has_comercio_items( $order ) ) {
			VEXPay_Logger::error( sprintf( 'Pago Móvil refused for order %d: it has comercio items (marketplace splits on).', $order_id ) );
			wc_add_notice( VEXPay_Marketplace::customer_refusal_message( $order ), 'error' );
			return array( 'result' => 'failure' );
		}

		$client = $this->get_api_client();

		// Reuse an open session: a buyer who already sent part of the money must land on the same payment.
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
				'methods'          => array( 'pago_movil' ),
				'successUrl'       => $this->get_return_url( $order ),
				'cancelUrl'        => $order->get_checkout_payment_url(),
				'expiresInMinutes' => self::SESSION_TTL_MINUTES,
				'metadata'         => array( 'orderId' => (string) $order_id ),
			)
		);

		if ( is_wp_error( $session ) || empty( $session['id'] ) || empty( $session['url'] ) ) {
			$message = is_wp_error( $session ) ? $session->get_error_message() : 'invalid session response';
			VEXPay_Logger::error( sprintf( 'Pago Móvil checkout session failed for order %d: %s', $order_id, $message ) );
			wc_add_notice( __( 'Pago Móvil is not available right now. Please choose another payment method.', 'vexpay-gateway-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( VEXPay_Helpers::META_EXTERNAL_REF, $external_ref );
		$order->update_meta_data( self::META_SESSION_ID, (string) $session['id'] );
		$order->update_meta_data( VEXPay_Helpers::META_STATUS, 'PENDING' );
		$order->update_status( 'pending', __( 'Awaiting Pago Móvil (VEX Pay hosted checkout).', 'vexpay-gateway-for-woocommerce' ) );
		$order->save();

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => (string) $session['url'],
		);
	}

	/**
	 * Thank-you page safety net.
	 *
	 * @param int $order_id Order ID.
	 */
	public function sync_order_from_session( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->is_paid() ) {
			return;
		}
		$this->sync_from_session( $order, $this->get_api_client() );
	}

	/**
	 * Re-read the order's checkout session and apply what it says (poller + thank-you page).
	 *
	 * @param WC_Order          $order  Order.
	 * @param VEXPay_API_Client $client API client.
	 * @return bool Whether VEX Pay answered.
	 */
	public function sync_from_session( WC_Order $order, VEXPay_API_Client $client ): bool {
		$session_id = (string) $order->get_meta( self::META_SESSION_ID );
		if ( '' === $session_id ) {
			return false;
		}
		$session = $client->get_checkout_session( $session_id );
		if ( is_wp_error( $session ) || ! is_array( $session ) ) {
			return false;
		}

		if ( ! empty( $session['paymentInProgress'] ) && is_array( $session['paymentInProgress'] ) ) {
			$this->apply_payment_result( $order, array_merge( $session['paymentInProgress'], array( 'status' => 'PENDING' ) ) );
			return true;
		}

		if ( ! empty( $session['paymentId'] ) ) {
			$payment = $client->get_payment( (string) $session['paymentId'] );
			if ( is_wp_error( $payment ) || ! is_array( $payment ) ) {
				return false;
			}
			$this->apply_payment_result( $order, $payment );
			return true;
		}

		// Session ended before any payment started (buyer never paid).
		if ( in_array( $session['status'] ?? '', array( 'expired', 'canceled' ), true ) ) {
			$this->apply_payment_result(
				$order,
				array(
					'status'      => 'FAILED',
					'failureCode' => (string) $session['status'],
				)
			);
		}
		return true;
	}

	/**
	 * Apply a Pago Móvil payment update (webhook `data`, session progress or payment) to the order.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $result Payment data.
	 * @param string   $event  Webhook event, '' for polls.
	 */
	public function apply_payment_result( WC_Order $order, array $result, string $event = '' ): void {
		$noted  = (string) $order->get_meta( VEXPay_Helpers::META_PM_PAID_NOTED );
		$update = VEXPay_Helpers::pago_movil_order_update( $result, $event, $order->is_paid(), $noted );

		if ( ! empty( $result['paymentId'] ) ) {
			$order->update_meta_data( VEXPay_Helpers::META_PAYMENT_ID, (string) $result['paymentId'] );
		}
		if ( $update['paid'] !== $noted ) {
			$order->update_meta_data( VEXPay_Helpers::META_PM_PAID_NOTED, $update['paid'] );
		}

		switch ( $update['action'] ) {
			case 'hold':
				$order->update_meta_data( VEXPay_Helpers::META_STATUS, 'PARTIAL' );
				if ( ! $order->has_status( 'on-hold' ) ) {
					$order->update_status( 'on-hold', $update['note'] );
				} elseif ( '' !== $update['note'] ) {
					$order->add_order_note( $update['note'] );
				}
				break;
			case 'complete':
				$order->update_meta_data( VEXPay_Helpers::META_STATUS, 'COMPLETED' );
				$order->payment_complete( (string) ( $result['paymentId'] ?? '' ) );
				$order->add_order_note( $update['note'] );
				break;
			case 'cancel':
				$order->update_meta_data( VEXPay_Helpers::META_STATUS, 'EXPIRED_REFUND' );
				if ( ! $order->has_status( 'cancelled' ) ) {
					$order->update_status( 'cancelled', $update['note'] );
				}
				break;
			case 'fail':
				$order->update_meta_data( VEXPay_Helpers::META_STATUS, 'FAILED' );
				if ( ! $order->has_status( 'failed' ) ) {
					$order->update_status( 'failed', $update['note'] );
				}
				break;
		}

		$order->save();
	}

	/**
	 * Keep WooCommerce's "Hold stock" from cancelling an order whose Pago Móvil can still arrive.
	 * VEX Pay ends every session, and the webhook or poller then sets the final state.
	 *
	 * @param bool     $cancel Whether WooCommerce wants to cancel the unpaid order.
	 * @param WC_Order $order  Order.
	 * @return bool
	 */
	public static function filter_cancel_unpaid_order( $cancel, $order ) {
		if ( $order instanceof WC_Order && 'vexpay_pago_movil' === $order->get_payment_method() ) {
			return false;
		}
		return $cancel;
	}

	/**
	 * Refunds go through VEX Pay support, not automatically.
	 *
	 * @param int        $order_id Order ID.
	 * @param float|null $amount   Amount.
	 * @param string     $reason   Reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		unset( $order_id, $amount, $reason );
		return new WP_Error(
			'vexpay_pago_movil_refund',
			__( 'Automatic refunds are not available for Pago Móvil. Ask VEX Pay support to refund the buyer.', 'vexpay-gateway-for-woocommerce' )
		);
	}
}
