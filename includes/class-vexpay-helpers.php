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

	public const META_PAYMENT_ID    = '_vexpay_payment_id';
	public const META_INTENT_ID     = '_vexpay_intent_id';
	public const META_EXTERNAL_REF  = '_vexpay_external_ref';
	public const META_DEBTOR_ID     = '_vexpay_debtor_id';
	public const META_DEBTOR_PHONE  = '_vexpay_debtor_phone';
	public const META_DEBTOR_BANK   = '_vexpay_debtor_bank';
	public const META_USD_AMOUNT    = '_vexpay_usd_amount';
	public const META_BCV_RATE      = '_vexpay_bcv_rate';
	public const META_VES_AMOUNT    = '_vexpay_ves_amount';
	public const META_STATUS        = '_vexpay_status';
	public const META_OTP_REQUESTED = '_vexpay_otp_requested';
	public const META_OTP_RESEND_AT = '_vexpay_otp_resend_at';

	/** Minimum seconds between OTP re-requests. */
	public const OTP_RESEND_COOLDOWN = 45;

	/** User meta / WC session key for remembered payer details. */
	public const PROFILE_META_KEY = '_vexpay_debtor_profile';

	/** WC session key (without underscore prefix). */
	public const PROFILE_SESSION_KEY = 'vexpay_debtor_profile';

	/** User meta for multiple remembered payer accounts. */
	public const ACCOUNTS_META_KEY = '_vexpay_debtor_accounts';

	/** WC session key for multiple remembered payer accounts. */
	public const ACCOUNTS_SESSION_KEY = 'vexpay_debtor_accounts';

	/** Max remembered payer accounts per customer/session. */
	public const MAX_DEBTOR_ACCOUNTS = 5;

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
	 * Format bank code as 4-digit SIMF string for checkout UI (e.g. 0102).
	 *
	 * @param string|int $code Bank code.
	 * @return string
	 */
	public static function format_simf_bank_code( $code ): string {
		$num = self::normalize_bank_code( $code );
		if ( null === $num ) {
			return '';
		}
		return str_pad( (string) $num, 4, '0', STR_PAD_LEFT );
	}

	/**
	 * Empty remembered payer profile shape.
	 *
	 * @return array{id_type:string,id_number:string,phone_prefix:string,phone_number:string,bank:string}
	 */
	public static function empty_debtor_profile(): array {
		return array(
			'id_type'      => 'V',
			'id_number'    => '',
			'phone_prefix' => '0412',
			'phone_number' => '',
			'bank'         => '',
		);
	}

	/**
	 * Split normalized debtor ID (V12345678) into type + number for form fields.
	 *
	 * @param string $normalized Normalized ID.
	 * @return array{type:string,number:string}
	 */
	public static function split_debtor_id( string $normalized ): array {
		if ( preg_match( '/^([VEJPG])(\d{6,9})$/', strtoupper( $normalized ), $m ) ) {
			$type = in_array( $m[1], self::debtor_id_types(), true ) ? $m[1] : 'V';
			return array(
				'type'   => $type,
				'number' => $m[2],
			);
		}
		return array(
			'type'   => 'V',
			'number' => '',
		);
	}

	/**
	 * Split normalized 58… phone into local prefix + 7-digit number.
	 *
	 * @param string $normalized Normalized phone (58XXXXXXXXXX).
	 * @return array{prefix:string,number:string}
	 */
	public static function split_phone( string $normalized ): array {
		$phone = self::normalize_phone( $normalized );
		if ( ! $phone || ! preg_match( '/^58(4\d{9})$/', $phone, $m ) ) {
			return array(
				'prefix' => '0412',
				'number' => '',
			);
		}

		$local = '0' . $m[1];
		foreach ( self::phone_prefixes() as $prefix ) {
			if ( 0 === strpos( $local, $prefix ) ) {
				return array(
					'prefix' => $prefix,
					'number' => substr( $local, strlen( $prefix ) ),
				);
			}
		}

		return array(
			'prefix' => '0412',
			'number' => substr( $local, 4 ),
		);
	}

	/**
	 * Build a checkout profile from normalized C2P payer values.
	 *
	 * @param string     $debtor_id Normalized ID.
	 * @param string     $phone     Normalized 58… phone.
	 * @param string|int $bank      Bank code (SIMF or int).
	 * @return array{id_type:string,id_number:string,phone_prefix:string,phone_number:string,bank:string}
	 */
	public static function profile_from_normalized( string $debtor_id, string $phone, $bank ): array {
		$id    = self::split_debtor_id( $debtor_id );
		$phone = self::split_phone( $phone );

		return self::sanitize_debtor_profile(
			array(
				'id_type'      => $id['type'],
				'id_number'    => $id['number'],
				'phone_prefix' => $phone['prefix'],
				'phone_number' => $phone['number'],
				'bank'         => self::format_simf_bank_code( $bank ),
			)
		);
	}

	/**
	 * Sanitize a stored/posted debtor profile for form prefills.
	 *
	 * @param mixed $raw Raw profile.
	 * @return array{id_type:string,id_number:string,phone_prefix:string,phone_number:string,bank:string}
	 */
	public static function sanitize_debtor_profile( $raw ): array {
		$profile = self::empty_debtor_profile();
		if ( ! is_array( $raw ) ) {
			return $profile;
		}

		$type = isset( $raw['id_type'] ) ? strtoupper( sanitize_text_field( (string) $raw['id_type'] ) ) : 'V';
		if ( ! in_array( $type, self::debtor_id_types(), true ) ) {
			$type = 'V';
		}

		$number = isset( $raw['id_number'] ) ? preg_replace( '/\D+/', '', (string) $raw['id_number'] ) : '';
		$number = is_string( $number ) ? substr( $number, 0, 9 ) : '';

		$prefix = isset( $raw['phone_prefix'] ) ? sanitize_text_field( (string) $raw['phone_prefix'] ) : '0412';
		if ( ! in_array( $prefix, self::phone_prefixes(), true ) ) {
			$prefix = '0412';
		}

		$phone_number = isset( $raw['phone_number'] ) ? preg_replace( '/\D+/', '', (string) $raw['phone_number'] ) : '';
		$phone_number = is_string( $phone_number ) ? substr( $phone_number, 0, 7 ) : '';

		$bank = isset( $raw['bank'] ) ? self::format_simf_bank_code( $raw['bank'] ) : '';

		return array(
			'id_type'      => $type,
			'id_number'    => $number,
			'phone_prefix' => $prefix,
			'phone_number' => $phone_number,
			'bank'         => $bank,
		);
	}

	/**
	 * Whether a profile has any useful remembered values.
	 *
	 * @param array $profile Profile.
	 * @return bool
	 */
	public static function debtor_profile_has_values( array $profile ): bool {
		$profile = self::sanitize_debtor_profile( $profile );
		return '' !== $profile['id_number']
			|| '' !== $profile['phone_number']
			|| '' !== $profile['bank'];
	}

	/**
	 * Stable fingerprint for a payer account (dedupe key).
	 *
	 * @param array $profile Profile.
	 * @return string
	 */
	public static function debtor_profile_fingerprint( array $profile ): string {
		$profile = self::sanitize_debtor_profile( $profile );
		return strtolower(
			$profile['id_type'] . $profile['id_number'] . '|' .
			$profile['phone_prefix'] . $profile['phone_number'] . '|' .
			$profile['bank']
		);
	}

	/**
	 * Whether a profile is complete enough to save as a reusable account.
	 *
	 * @param array $profile Profile.
	 * @return bool
	 */
	public static function debtor_profile_is_complete( array $profile ): bool {
		$profile = self::sanitize_debtor_profile( $profile );
		$id      = self::normalize_debtor_id( $profile['id_type'] . $profile['id_number'] );
		$phone   = self::normalize_phone( $profile['phone_prefix'] . $profile['phone_number'] );
		$bank    = self::normalize_bank_code( $profile['bank'] );
		return (bool) $id && (bool) $phone && null !== $bank;
	}

	/**
	 * Sanitize a list of remembered payer accounts.
	 *
	 * @param mixed $raw Raw list.
	 * @return array<int, array{id_type:string,id_number:string,phone_prefix:string,phone_number:string,bank:string}>
	 */
	public static function sanitize_debtor_accounts( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out  = array();
		$seen = array();
		foreach ( $raw as $item ) {
			$profile = self::sanitize_debtor_profile( $item );
			if ( ! self::debtor_profile_is_complete( $profile ) ) {
				continue;
			}
			$fp = self::debtor_profile_fingerprint( $profile );
			if ( isset( $seen[ $fp ] ) ) {
				continue;
			}
			$seen[ $fp ] = true;
			$out[]       = $profile;
			if ( count( $out ) >= self::MAX_DEBTOR_ACCOUNTS ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Upsert a profile into the accounts list (most recent first).
	 *
	 * @param array $accounts Existing accounts.
	 * @param array $profile  Profile to upsert.
	 * @return array<int, array{id_type:string,id_number:string,phone_prefix:string,phone_number:string,bank:string}>
	 */
	public static function upsert_debtor_account( array $accounts, array $profile ): array {
		$profile = self::sanitize_debtor_profile( $profile );
		if ( ! self::debtor_profile_is_complete( $profile ) ) {
			return self::sanitize_debtor_accounts( $accounts );
		}

		$fp  = self::debtor_profile_fingerprint( $profile );
		$out = array( $profile );
		foreach ( self::sanitize_debtor_accounts( $accounts ) as $existing ) {
			if ( self::debtor_profile_fingerprint( $existing ) === $fp ) {
				continue;
			}
			$out[] = $existing;
			if ( count( $out ) >= self::MAX_DEBTOR_ACCOUNTS ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Display format for cédula/RIF: V-18.819.897
	 *
	 * @param string $type   Document type letter.
	 * @param string $number Digits only.
	 * @return string
	 */
	public static function format_debtor_id_display( string $type, string $number ): string {
		$type   = strtoupper( sanitize_text_field( $type ) );
		$number = preg_replace( '/\D+/', '', $number );
		$number = is_string( $number ) ? $number : '';

		if ( '' === $type && '' === $number ) {
			return '';
		}
		if ( '' === $number ) {
			return $type;
		}

		// Group thousands from the right: 18819897 → 18.819.897
		$grouped = '';
		$digits  = $number;
		while ( strlen( $digits ) > 3 ) {
			$grouped = '.' . substr( $digits, -3 ) . $grouped;
			$digits  = substr( $digits, 0, -3 );
		}
		$grouped = $digits . $grouped;

		return $type . '-' . $grouped;
	}

	/**
	 * Human label for a saved payer account.
	 *
	 * @param array       $profile   Profile.
	 * @param string|null $bank_name Optional bank display name.
	 * @return string
	 */
	public static function debtor_account_label( array $profile, ?string $bank_name = null ): string {
		$profile = self::sanitize_debtor_profile( $profile );
		$id      = self::format_debtor_id_display( $profile['id_type'], $profile['id_number'] );
		$phone   = $profile['phone_prefix'];
		$digits  = $profile['phone_number'];
		if ( strlen( $digits ) >= 4 ) {
			$phone .= '•••' . substr( $digits, -4 );
		} elseif ( '' !== $digits ) {
			$phone .= $digits;
		}

		$parts = array_filter(
			array(
				$id,
				$phone,
				$bank_name ? $bank_name : $profile['bank'],
			)
		);

		return implode( ' · ', $parts );
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
			case 'ACCP':
				return 'complete';
			case 'FAILED':
			case 'CANCELED':
				return 'fail';
			case 'REVERSED':
				return 'refund';
			case 'PENDING':
			case 'AC00':
				return 'hold';
			default:
				return 'ignore';
		}
	}

	/**
	 * Map a débito inmediato execute wire payload into a status-bearing receipt.
	 *
	 * @param array $result API response.
	 * @return array
	 */
	public static function normalize_debit_execute_result( array $result ): array {
		if ( empty( $result['status'] ) && ! empty( $result['code'] ) ) {
			$code = strtoupper( (string) $result['code'] );
			if ( 'ACCP' === $code ) {
				$result['status'] = 'COMPLETED';
			} elseif ( 'AC00' === $code ) {
				$result['status'] = 'PENDING';
			} else {
				$result['status'] = 'FAILED';
				if ( empty( $result['failureCode'] ) ) {
					$result['failureCode'] = $code;
				}
			}
		}

		if ( empty( $result['bankReference'] ) ) {
			$ref = '';
			if ( ! empty( $result['reference'] ) ) {
				$ref = (string) $result['reference'];
			} elseif ( ! empty( $result['id'] ) ) {
				$ref = (string) $result['id'];
			} elseif ( ! empty( $result['Id'] ) ) {
				$ref = (string) $result['Id'];
			}
			if ( '' !== $ref ) {
				$result['bankReference'] = $ref;
			}
		}

		return $result;
	}

	/**
	 * Payer display name for débito (max 20 chars).
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function payer_nombre_from_order( WC_Order $order ): string {
		$name = trim( $order->get_formatted_billing_full_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}
		if ( '' === $name ) {
			$name = 'Cliente';
		}
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $name, 0, 20 );
		}
		return substr( $name, 0, 20 );
	}

	/**
	 * Statement concept for débito (max 30 chars).
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function debit_concepto_for_order( WC_Order $order ): string {
		$concepto = sprintf(
			/* translators: %s: order number */
			__( 'Order #%s', 'woo-vexpay-gateway' ),
			$order->get_order_number()
		);
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $concepto, 0, 30 );
		}
		return substr( $concepto, 0, 30 );
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
