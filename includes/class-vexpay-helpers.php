<?php
/**
 * Pure helpers (safe to unit-test without WordPress bootstrap where possible).
 *
 * @package VEXPay_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Helpers.
 */
class VEXPay_Helpers {

	public const META_PAYMENT_ID   = '_vexpay_payment_id';
	public const META_INTENT_ID    = '_vexpay_intent_id';
	public const META_EXTERNAL_REF = '_vexpay_external_ref';
	public const META_DEBTOR_ID    = '_vexpay_debtor_id';
	public const META_DEBTOR_PHONE = '_vexpay_debtor_phone';
	public const META_DEBTOR_BANK  = '_vexpay_debtor_bank';
	public const META_USD_AMOUNT   = '_vexpay_usd_amount';
	public const META_STATUS       = '_vexpay_status';

	/**
	 * Normalize GET /v1/banks payload into [{code,name,logoUrl}, …].
	 *
	 * @param mixed $result API response body.
	 * @return array<int, array{code:string,name:string,logoUrl:?string}>
	 */
	public static function normalize_banks_list( $result ): array {
		if ( ! is_array( $result ) ) {
			return array();
		}

		$list = isset( $result['banks'] ) && is_array( $result['banks'] ) ? $result['banks'] : $result;
		if ( ! is_array( $list ) ) {
			return array();
		}

		$banks = array();
		foreach ( $list as $bank ) {
			if ( ! is_array( $bank ) || empty( $bank['code'] ) ) {
				continue;
			}
			$code = (string) $bank['code'];
			$name = isset( $bank['name'] ) ? (string) $bank['name'] : $code;
			$logo = null;
			if ( ! empty( $bank['logoUrl'] ) && is_string( $bank['logoUrl'] ) ) {
				$raw = trim( $bank['logoUrl'] );
				if ( function_exists( 'esc_url_raw' ) ) {
					$logo = esc_url_raw( $raw );
					$logo = '' !== $logo ? $logo : null;
				} elseif ( filter_var( $raw, FILTER_VALIDATE_URL ) ) {
					$logo = $raw;
				}
			}
			$banks[] = array(
				'code'    => $code,
				'name'    => $name,
				'logoUrl' => $logo,
			);
		}

		return $banks;
	}

	/**
	 * Build externalRef for an order (max 64 chars).
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function external_ref_for_order( int $order_id ): string {
		return 'wc_order_' . $order_id;
	}

	/**
	 * Normalize Venezuelan cédula/RIF to V12345678 form.
	 *
	 * @param string $raw Raw input.
	 * @return string|null Normalized or null if invalid.
	 */
	public static function normalize_debtor_id( string $raw ): ?string {
		$value = strtoupper( preg_replace( '/[\s\-\.]/', '', $raw ) ?? '' );
		if ( ! preg_match( '/^[VEJPG]\d{6,9}$/', $value ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * Allowed cédula/RIF type letters for the checkout selector.
	 *
	 * @return string[]
	 */
	public static function debtor_id_types(): array {
		return array( 'V', 'J', 'E' );
	}

	/**
	 * Allowed local mobile prefixes (04XX).
	 *
	 * @return string[]
	 */
	public static function phone_prefixes(): array {
		return array( '0412', '0422', '0414', '0424', '0416', '0426' );
	}

	/**
	 * Build debtor ID from type letter + number (digits only).
	 *
	 * @param string $type   V|J|E (also accepts P|G for API compatibility).
	 * @param string $number Document number.
	 * @return string|null
	 */
	public static function compose_debtor_id( string $type, string $number ): ?string {
		$type   = strtoupper( trim( $type ) );
		$digits = preg_replace( '/\D+/', '', $number ) ?? '';
		if ( '' === $type || '' === $digits ) {
			return null;
		}
		return self::normalize_debtor_id( $type . $digits );
	}

	/**
	 * Build phone from 04XX prefix + 7-digit subscriber number.
	 *
	 * @param string $prefix Local prefix (0412…).
	 * @param string $number Seven-digit local number.
	 * @return string|null Normalized 58… phone or null.
	 */
	public static function compose_phone( string $prefix, string $number ): ?string {
		$prefix = preg_replace( '/\D+/', '', $prefix ) ?? '';
		$digits = preg_replace( '/\D+/', '', $number ) ?? '';

		if ( ! in_array( $prefix, self::phone_prefixes(), true ) ) {
			return null;
		}
		if ( ! preg_match( '/^\d{7}$/', $digits ) ) {
			return null;
		}

		return self::normalize_phone( $prefix . $digits );
	}

	/**
	 * Resolve debtor ID from combined field or type+number parts.
	 *
	 * @return string Raw combined value (may still be invalid).
	 */
	public static function resolve_debtor_id_from_request(): string {
		$type   = self::get_request_field( 'vexpay_debtor_id_type' );
		$number = self::get_request_field( 'vexpay_debtor_id_number' );
		if ( '' !== $type || '' !== $number ) {
			$composed = self::compose_debtor_id( $type, $number );
			return $composed ? $composed : ( strtoupper( trim( $type ) ) . preg_replace( '/\D+/', '', $number ) );
		}
		return self::get_request_field( 'vexpay_debtor_id' );
	}

	/**
	 * Resolve phone from combined field or prefix+number parts.
	 *
	 * @return string Raw local/combined value (may still be invalid).
	 */
	public static function resolve_phone_from_request(): string {
		$prefix = self::get_request_field( 'vexpay_debtor_phone_prefix' );
		$number = self::get_request_field( 'vexpay_debtor_phone_number' );
		if ( '' !== $prefix || '' !== $number ) {
			$composed = self::compose_phone( $prefix, $number );
			if ( $composed ) {
				return $composed;
			}
			$p = preg_replace( '/\D+/', '', $prefix ) ?? '';
			$n = preg_replace( '/\D+/', '', $number ) ?? '';
			return $p . $n;
		}
		return self::get_request_field( 'vexpay_debtor_phone' );
	}

	/**
	 * Normalize phone to 58XXXXXXXXXX.
	 *
	 * @param string $raw Raw input.
	 * @return string|null
	 */
	public static function normalize_phone( string $raw ): ?string {
		$digits = preg_replace( '/\D+/', '', $raw ) ?? '';

		if ( preg_match( '/^58\d{10}$/', $digits ) ) {
			return $digits;
		}

		// Local mobile 04XX…
		if ( preg_match( '/^0(4\d{9})$/', $digits, $m ) ) {
			return '58' . $m[1];
		}

		// Without leading 0: 4XXXXXXXXX
		if ( preg_match( '/^4\d{9}$/', $digits ) ) {
			return '58' . $digits;
		}

		return null;
	}

	/**
	 * Parse bank code from SIMF string or int to API integer (e.g. 102).
	 *
	 * @param string|int $code Bank code.
	 * @return int|null
	 */
	public static function normalize_bank_code( $code ): ?int {
		$digits = preg_replace( '/\D+/', '', (string) $code ) ?? '';
		if ( '' === $digits ) {
			return null;
		}
		$num = (int) $digits;
		return $num > 0 ? $num : null;
	}

	/**
	 * Validate OTP token (6–8 digits).
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	public static function is_valid_token( string $token ): bool {
		return (bool) preg_match( '/^\d{6,8}$/', $token );
	}

	/**
	 * Verify VEXPay webhook HMAC.
	 *
	 * Signature header format: sha256=<hex>
	 *
	 * @param string $raw_body       Raw request body.
	 * @param string $secret         Endpoint secret.
	 * @param string $signature_header Header value.
	 * @return bool
	 */
	public static function verify_webhook_signature( string $raw_body, string $secret, string $signature_header ): bool {
		if ( '' === $secret || '' === $signature_header ) {
			return false;
		}

		$expected = 'sha256=' . hash_hmac( 'sha256', $raw_body, $secret );
		return hash_equals( $expected, trim( $signature_header ) );
	}

	/**
	 * Map VEXPay payment status to a Woo action hint.
	 *
	 * @param string $status VEXPay status.
	 * @return string One of: complete|fail|hold|refund|ignore
	 */
	public static function map_payment_status( string $status ): string {
		switch ( strtoupper( $status ) ) {
			case 'COMPLETED':
				return 'complete';
			case 'FAILED':
			case 'CANCELED':
				return 'fail';
			case 'REVERSED':
				return 'refund';
			case 'PENDING':
				return 'hold';
			default:
				return 'ignore';
		}
	}

	/**
	 * Order total as USD float for the API (store assumed USD for v1).
	 *
	 * @param WC_Order $order Order.
	 * @return float
	 */
	public static function order_usd_amount( WC_Order $order ): float {
		return round( (float) $order->get_total(), 2 );
	}

	/**
	 * Human-readable success message after a connection probe.
	 *
	 * @param int   $bank_count Banks returned.
	 * @param float $bcv_rate   BCV rate from quote.
	 * @return string
	 */
	public static function format_connection_success( int $bank_count, float $bcv_rate ): string {
		return sprintf(
			/* translators: 1: bank count 2: BCV rate */
			__( 'Connected to VEXPay. API key is valid. %1$d banks available; BCV rate %2$s.', 'woo-vexpay-gateway' ),
			$bank_count,
			number_format( $bcv_rate, 4, '.', '' )
		);
	}

	/**
	 * Read a checkout field from classic POST or Blocks payment_data.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	public static function get_request_field( string $key ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Called inside WC checkout / Store API flows.
		if ( isset( $_POST[ $key ] ) ) {
			return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
		}

		if ( isset( $_POST['payment_data'] ) && is_array( $_POST['payment_data'] ) ) {
			foreach ( $_POST['payment_data'] as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$entry_key = isset( $entry['key'] ) ? (string) $entry['key'] : '';
				if ( $entry_key === $key && isset( $entry['value'] ) ) {
					return sanitize_text_field( wp_unslash( (string) $entry['value'] ) );
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return '';
	}
}
