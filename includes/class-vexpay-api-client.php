<?php
/**
 * VEXPay HTTP API client.
 *
 * @package VEXPay_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * API client.
 */
class VEXPay_API_Client {

	/**
	 * Base URL without trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Timeout seconds.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Constructor.
	 *
	 * @param string $base_url Base URL.
	 * @param string $api_key  API key.
	 * @param int    $timeout  Timeout.
	 */
	public function __construct( string $base_url, string $api_key, int $timeout = 30 ) {
		$this->base_url = untrailingslashit( $base_url );
		$this->api_key  = $api_key;
		$this->timeout  = $timeout;
	}

	/**
	 * GET /v1/banks
	 *
	 * @return array|WP_Error
	 */
	public function get_banks() {
		return $this->request( 'GET', '/v1/banks' );
	}

	/**
	 * GET /v1/quote
	 *
	 * @param float $usd_amount USD amount.
	 * @return array|WP_Error
	 */
	public function get_quote( float $usd_amount ) {
		return $this->request( 'GET', '/v1/quote?usdAmount=' . rawurlencode( (string) $usd_amount ) );
	}

	/**
	 * POST /v1/payments/debit/otp — request débito inmediato bank OTP (VES).
	 *
	 * @param array $body Body (banco, monto, telefono, cedula).
	 * @return array|WP_Error
	 */
	public function debit_otp( array $body ) {
		return $this->request( 'POST', '/v1/payments/debit/otp', $body );
	}

	/**
	 * POST /v1/payments/debit — execute débito inmediato.
	 *
	 * @param array $body Body (banco, monto, telefono, cedula, nombre, otp, concepto, externalRef?).
	 * @return array|WP_Error
	 */
	public function debit_execute( array $body ) {
		return $this->request( 'POST', '/v1/payments/debit', $body );
	}

	/**
	 * GET /v1/payments/:id
	 *
	 * @param string $payment_id Payment ID.
	 * @return array|WP_Error
	 */
	public function get_payment( string $payment_id ) {
		return $this->request( 'GET', '/v1/payments/' . rawurlencode( $payment_id ) );
	}

	/**
	 * GET /v1/payments/by-ref/:externalRef
	 *
	 * @param string $external_ref External ref.
	 * @return array|WP_Error
	 */
	public function get_payment_by_ref( string $external_ref ) {
		return $this->request( 'GET', '/v1/payments/by-ref/' . rawurlencode( $external_ref ) );
	}

	/**
	 * POST /v1/payments/operations/poll — nudge gateway reconciliation for a pending entity.
	 *
	 * @param string $kind Entity kind (`debit`, `payout`, `verification_deposit`).
	 * @param string $id   Payment / payout / deposit UUID (not network operation id).
	 * @return array|WP_Error
	 */
	public function poll_payment_operation( string $kind, string $id ) {
		return $this->request(
			'POST',
			'/v1/payments/operations/poll',
			array(
				'kind' => $kind,
				'id'   => $id,
			)
		);
	}

	/**
	 * POST /v1/payments/:id/reverse
	 *
	 * @param string $payment_id Payment ID.
	 * @return array|WP_Error
	 */
	public function reverse_payment( string $payment_id ) {
		return $this->request( 'POST', '/v1/payments/' . rawurlencode( $payment_id ) . '/reverse' );
	}

	/**
	 * POST /v1/webhooks
	 *
	 * @param string $url    URL.
	 * @param array  $events Events.
	 * @param string $secret Optional secret.
	 * @return array|WP_Error
	 */
	public function create_webhook( string $url, array $events, string $secret = '' ) {
		$body = array(
			'url'    => $url,
			'events' => $events,
		);
		if ( '' !== $secret ) {
			$body['secret'] = $secret;
		}
		return $this->request( 'POST', '/v1/webhooks', $body );
	}

	/**
	 * GET /v1/webhooks
	 *
	 * @return array|WP_Error
	 */
	public function list_webhooks() {
		return $this->request( 'GET', '/v1/webhooks' );
	}

	/**
	 * PATCH /v1/webhooks/:id
	 *
	 * @param string $id   Endpoint ID.
	 * @param array  $body Body.
	 * @return array|WP_Error
	 */
	public function update_webhook( string $id, array $body ) {
		return $this->request( 'PATCH', '/v1/webhooks/' . rawurlencode( $id ), $body );
	}

	/**
	 * Probe API key + reachability (banks + quote).
	 *
	 * @return array{ok:bool,message:string,bank_count?:int,bcv_rate?:float,details?:string}|WP_Error
	 */
	public function test_connection() {
		$banks = $this->get_banks();
		if ( is_wp_error( $banks ) ) {
			return $banks;
		}

		$list = isset( $banks['banks'] ) && is_array( $banks['banks'] ) ? $banks['banks'] : $banks;
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		$bank_count = count( $list );

		$quote = $this->get_quote( 1.0 );
		if ( is_wp_error( $quote ) ) {
			return $quote;
		}

		$bcv = isset( $quote['bcvRate'] ) ? (float) $quote['bcvRate'] : 0.0;

		return array(
			'ok'         => true,
			'bank_count' => $bank_count,
			'bcv_rate'   => $bcv,
			'message'    => VEXPay_Helpers::format_connection_success( $bank_count, $bcv ),
		);
	}

	/**
	 * HTTP request.
	 *
	 * @param string     $method Method.
	 * @param string     $path   Path.
	 * @param array|null $body   JSON body.
	 * @return array|WP_Error
	 */
	private function request( string $method, string $path, ?array $body = null ) {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'vexpay_missing_key', __( 'VEXPay API key is not configured.', 'vexpay-gateway-for-woocommerce' ) );
		}

		$url  = $this->base_url . $path;
		$args = array(
			'method'  => $method,
			'timeout' => $this->timeout,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'x-api-key'    => $this->api_key,
				'User-Agent'   => 'woo-vexpay-gateway/' . VEXPAY_GATEWAY_VERSION,
			),
		);

		if ( null !== $body ) {
			// Keep fractional monto (e.g. 50.0) as 50.0 instead of 50 — matches VES wire examples.
			$flags        = defined( 'JSON_PRESERVE_ZERO_FRACTION' ) ? JSON_PRESERVE_ZERO_FRACTION : 0;
			$args['body'] = wp_json_encode( $body, $flags );
		}

		VEXPay_Logger::debug( sprintf( 'API %s %s', $method, $path ) );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			VEXPay_Logger::error( 'API transport error: ' . $response->get_error_message() );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = __( 'VEXPay API request failed.', 'vexpay-gateway-for-woocommerce' );
			if ( is_array( $data ) ) {
				if ( ! empty( $data['message'] ) && is_string( $data['message'] ) ) {
					$message = $data['message'];
				} elseif ( ! empty( $data['error'] ) && is_string( $data['error'] ) ) {
					$message = $data['error'];
				}
			}
			VEXPay_Logger::error( sprintf( 'API %s %s → %d: %s', $method, $path, $code, $message ) );
			return new WP_Error( 'vexpay_api_error', $message, array( 'status' => $code, 'body' => $data ) );
		}

		$biz = '';
		if ( is_array( $data ) && isset( $data['code'] ) && ( is_string( $data['code'] ) || is_numeric( $data['code'] ) ) ) {
			$biz = ' code=' . (string) $data['code'];
		}
		VEXPay_Logger::debug( sprintf( 'API %s %s → %d%s', $method, $path, $code, $biz ) );

		return is_array( $data ) ? $data : array();
	}
}
