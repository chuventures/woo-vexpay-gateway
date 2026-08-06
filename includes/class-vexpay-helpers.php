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
