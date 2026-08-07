<?php
/**
 * Incoming VEXPay webhooks.
 *
 * @package VEXPay_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Webhook handler.
 */
class VEXPay_Webhook {

	/**
	 * Register WC API endpoint.
	 */
	public static function init(): void {
		add_action( 'woocommerce_api_vexpay_webhook', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Handle POST.
	 */
	public static function handle(): void {
		$raw = file_get_contents( 'php://input' );
		if ( false === $raw ) {
			$raw = '';
		}

		$settings = get_option( 'woocommerce_vexpay_settings', array() );
		$secret   = isset( $settings['webhook_secret'] ) ? (string) $settings['webhook_secret'] : '';

		$signature = '';
		if ( isset( $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ) ) {
			$signature = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ) );
		}

		if ( ! VEXPay_Helpers::verify_webhook_signature( $raw, $secret, $signature ) ) {
			VEXPay_Logger::error( 'Webhook signature verification failed.' );
			status_header( 401 );
			echo esc_html__( 'Invalid signature', 'vexpay-gateway-for-woocommerce' );
			exit;
		}

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			status_header( 400 );
			echo esc_html__( 'Invalid JSON', 'vexpay-gateway-for-woocommerce' );
			exit;
		}

		$event = isset( $payload['event'] ) ? (string) $payload['event'] : '';
		$data  = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();

		VEXPay_Logger::info( 'Webhook received: ' . $event );

		self::reconcile( $event, $data );

		status_header( 200 );
		echo 'ok';
		exit;
	}

	/**
	 * Reconcile order from webhook data.
	 *
	 * @param string $event Event name.
	 * @param array  $data  Payload data.
	 */
	public static function reconcile( string $event, array $data ): void {
		$order = self::find_order( $data );
		if ( ! $order ) {
			VEXPay_Logger::debug( 'Webhook: order not found for event ' . $event );
			return;
		}

		if ( $order->get_payment_method() !== 'vexpay' ) {
			return;
		}

		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( empty( $gateways['vexpay'] ) || ! $gateways['vexpay'] instanceof VEXPay_Gateway ) {
			return;
		}

		/** @var VEXPay_Gateway $gateway */
		$gateway = $gateways['vexpay'];

		// Normalize receipt-like array from webhook data.
		$receipt = $data;
		if ( empty( $receipt['status'] ) && str_starts_with( $event, 'payment.' ) ) {
			$map = array(
				'payment.completed' => 'COMPLETED',
				'payment.failed'    => 'FAILED',
				'payment.canceled'  => 'CANCELED',
				'payment.reversed'  => 'REVERSED',
				'payment.pending'   => 'PENDING',
			);
			if ( isset( $map[ $event ] ) ) {
				$receipt['status'] = $map[ $event ];
			}
		}

		$gateway->apply_payment_result( $order, $receipt );
	}

	/**
	 * Find order by externalRef or paymentId meta.
	 *
	 * @param array $data Webhook data.
	 * @return WC_Order|null
	 */
	private static function find_order( array $data ): ?WC_Order {
		$external_ref = isset( $data['externalRef'] ) ? (string) $data['externalRef'] : '';
		$payment_id   = isset( $data['paymentId'] ) ? (string) $data['paymentId'] : '';

		if ( '' !== $external_ref && preg_match( '/^wc_order_(\d+)$/', $external_ref, $m ) ) {
			$order = wc_get_order( (int) $m[1] );
			if ( $order ) {
				return $order;
			}
		}

		if ( '' !== $external_ref ) {
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					// Necessary lookup: external reference is stored as order meta and has no WC native field.
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'   => VEXPay_Helpers::META_EXTERNAL_REF,
							'value' => $external_ref,
						),
					),
				)
			);
			if ( ! empty( $orders[0] ) ) {
				return $orders[0];
			}
		}

		if ( '' !== $payment_id ) {
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					// Necessary lookup: VEXPay payment ID is stored as order meta and has no WC native field.
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'   => VEXPay_Helpers::META_PAYMENT_ID,
							'value' => $payment_id,
						),
					),
				)
			);
			if ( ! empty( $orders[0] ) ) {
				return $orders[0];
			}
		}

		return null;
	}
}
