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
	/** Unix timestamp of last settlement poll attempt. */
	public const META_LAST_POLLED_AT = '_vexpay_last_polled_at';

	/** Minimum seconds between OTP re-requests. */
	public const OTP_RESEND_COOLDOWN = 45;

	/** WP-Cron / Action Scheduler hook for pending payment settlement. */
	public const CRON_HOOK = 'vexpay_poll_pending_payments';

	/** Custom WP-Cron schedule name (1 minute). */
	public const CRON_SCHEDULE = 'vexpay_every_minute';

	/** Seconds between recurring poll runs. */
	public const POLL_INTERVAL = 60;

	/** Max orders to reconcile per cron tick. */
	public const POLL_BATCH_LIMIT = 15;

	/** Min seconds between polls for the same order. */
	public const POLL_ORDER_COOLDOWN = 45;

	/** Stop polling orders older than this many seconds. */
	public const POLL_MAX_AGE = 172800; // 48 hours.

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
	 * Remove a profile from the accounts list (match by fingerprint).
	 *
	 * @param array $accounts Existing accounts.
	 * @param array $profile  Profile to remove.
	 * @return array<int, array{id_type:string,id_number:string,phone_prefix:string,phone_number:string,bank:string}>
	 */
	public static function remove_debtor_account( array $accounts, array $profile ): array {
		$profile = self::sanitize_debtor_profile( $profile );
		if ( ! self::debtor_profile_is_complete( $profile ) ) {
			return self::sanitize_debtor_accounts( $accounts );
		}

		$fp  = self::debtor_profile_fingerprint( $profile );
		$out = array();
		foreach ( self::sanitize_debtor_accounts( $accounts ) as $existing ) {
			if ( self::debtor_profile_fingerprint( $existing ) === $fp ) {
				continue;
			}
			$out[] = $existing;
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
	 * Whether a VEXPay status is still awaiting bank settlement.
	 *
	 * @param string $status Meta / API status.
	 * @return bool
	 */
	public static function is_pending_settlement_status( string $status ): bool {
		return in_array( strtoupper( $status ), array( 'PENDING', 'AC00' ), true );
	}

	/**
	 * Thank-you / order-received panel phase for a VEXPay order (no WP/WC calls).
	 *
	 * @param string $vexpay_status `_vexpay_status` meta.
	 * @param string $payment_id    `_vexpay_payment_id` meta.
	 * @param bool   $is_paid       Whether the WooCommerce order is paid.
	 * @param string $wc_status     WooCommerce order status slug (optional).
	 * @return string One of: paid|failed|refunded|settling|otp|other
	 */
	public static function thankyou_panel_phase(
		string $vexpay_status,
		string $payment_id,
		bool $is_paid,
		string $wc_status = ''
	): string {
		$status = strtoupper( trim( $vexpay_status ) );
		$action = self::map_payment_status( $status );

		if ( $is_paid || 'complete' === $action ) {
			return 'paid';
		}
		if ( 'fail' === $action || 'failed' === $wc_status ) {
			return 'failed';
		}
		if ( 'refund' === $action || 'refunded' === $wc_status ) {
			return 'refunded';
		}
		if ( '' !== trim( $payment_id ) && self::is_pending_settlement_status( $status ) ) {
			return 'settling';
		}
		if ( in_array( $status, array( '', 'PENDING' ), true ) ) {
			return 'otp';
		}
		return 'other';
	}

	/**
	 * Prefer bank / WC transaction id, else payment UUID. Empty when neither exists.
	 *
	 * @param string $transaction_id WC transaction id or bank reference.
	 * @param string $payment_id     `_vexpay_payment_id` meta.
	 * @return string
	 */
	public static function thankyou_transaction_id( string $transaction_id, string $payment_id ): string {
		$txn = trim( $transaction_id );
		if ( '' !== $txn ) {
			return $txn;
		}
		return trim( $payment_id );
	}

	/**
	 * Whether an order is a candidate for settlement polling (no WP/WC calls).
	 *
	 * Candidates have a stored payment id (OTP execute created a payment) and a
	 * pending VEXPay status. Pre-OTP on-hold orders are excluded.
	 *
	 * @param string $payment_method Order payment method.
	 * @param string $vexpay_status  `_vexpay_status` meta.
	 * @param string $payment_id     `_vexpay_payment_id` meta.
	 * @param bool   $is_paid        Whether the order is already paid.
	 * @return bool
	 */
	public static function order_awaits_settlement_poll(
		string $payment_method,
		string $vexpay_status,
		string $payment_id,
		bool $is_paid
	): bool {
		return null === self::settlement_poll_eligibility_skip_reason(
			$payment_method,
			$vexpay_status,
			$payment_id,
			$is_paid
		);
	}

	/**
	 * Why an order is not eligible for settlement API lookup (null = eligible).
	 *
	 * Does not cover cooldown / max-age — those are checked separately after eligibility.
	 *
	 * @param string $payment_method Order payment method.
	 * @param string $vexpay_status  `_vexpay_status` meta.
	 * @param string $payment_id     `_vexpay_payment_id` meta.
	 * @param bool   $is_paid        Whether the order is already paid.
	 * @return string|null Machine-readable skip reason, or null when eligible.
	 */
	public static function settlement_poll_eligibility_skip_reason(
		string $payment_method,
		string $vexpay_status,
		string $payment_id,
		bool $is_paid
	): ?string {
		if ( 'vexpay' !== $payment_method ) {
			return 'not_vexpay';
		}
		if ( $is_paid ) {
			return 'already_paid';
		}
		if ( '' === trim( $payment_id ) ) {
			// Checkout / OTP-request set PENDING without a payment row yet.
			return 'missing_payment_id';
		}
		if ( ! self::is_pending_settlement_status( $vexpay_status ) ) {
			$status = strtoupper( trim( $vexpay_status ) );
			return '' === $status ? 'status_empty' : 'status_not_pending:' . $status;
		}
		return null;
	}

	/**
	 * Whether the order was polled too recently.
	 *
	 * @param int $last_polled_at Unix timestamp from meta (0 if never).
	 * @param int $now            Current unix time.
	 * @param int $cooldown       Seconds between polls.
	 * @return bool
	 */
	public static function was_polled_recently( int $last_polled_at, int $now, int $cooldown = self::POLL_ORDER_COOLDOWN ): bool {
		if ( $last_polled_at <= 0 ) {
			return false;
		}
		return ( $now - $last_polled_at ) < $cooldown;
	}

	/**
	 * Whether the order is too old to keep polling.
	 *
	 * @param int $created_at Unix timestamp (order created).
	 * @param int $now        Current unix time.
	 * @param int $max_age    Max age in seconds.
	 * @return bool
	 */
	public static function is_poll_expired( int $created_at, int $now, int $max_age = self::POLL_MAX_AGE ): bool {
		if ( $created_at <= 0 ) {
			return false;
		}
		return ( $now - $created_at ) > $max_age;
	}

	/**
	 * Normalize GET payment / by-ref / operations-poll payloads into a receipt
	 * suitable for {@see VEXPay_Gateway::apply_payment_result()}.
	 *
	 * @param array $result API body.
	 * @return array
	 */
	public static function normalize_payment_lookup_result( array $result ): array {
		// ManualOperationPollResponseDto uses `id` for the payment UUID.
		if ( empty( $result['paymentId'] ) && ! empty( $result['id'] ) && ! empty( $result['status'] ) ) {
			$result['paymentId'] = (string) $result['id'];
		}

		if ( empty( $result['status'] ) && ! empty( $result['code'] ) ) {
			$result = self::normalize_debit_execute_result( $result );
		}

		if ( empty( $result['bankReference'] ) && ! empty( $result['reference'] ) ) {
			$result['bankReference'] = (string) $result['reference'];
		}

		if ( ! empty( $result['status'] ) ) {
			$result['status'] = strtoupper( (string) $result['status'] );
		}

		return $result;
	}

	/**
	 * Round a VES amount to 2 decimal places for débito API payloads.
	 *
	 * @param float|int|string $amount Amount in bolívares.
	 * @return float
	 */
	public static function format_ves_amount( $amount ): float {
		return round( (float) $amount, 2 );
	}

	/**
	 * Classify an API key twin without exposing the secret.
	 *
	 * Mode is implied by which twin key the API receives (see VEXPay auth docs).
	 * Test twins simulate débito OTP (Portal → Sandbox); Live twins reach R4 SMS.
	 *
	 * @param string $api_key Raw x-api-key.
	 * @return string One of: live|test|missing|unknown
	 */
	public static function api_key_kind( string $api_key ): string {
		$key = trim( $api_key );
		if ( '' === $key ) {
			return 'missing';
		}

		if ( preg_match( '/^vk_test_/i', $key ) ) {
			return 'test';
		}
		if ( preg_match( '/^vk_live_/i', $key ) ) {
			return 'live';
		}

		// Local seed twins: dev-local-api-key (live) / dev-local-api-key-test (test).
		$lower = strtolower( $key );
		if ( str_ends_with( $lower, '-test' ) || str_ends_with( $lower, '_test' ) ) {
			return 'test';
		}
		if ( 'dev-local-api-key' === $lower ) {
			return 'live';
		}

		return 'unknown';
	}

	/**
	 * Whether débito OTP is expected to be simulated (no bank SMS).
	 *
	 * True when the Woo sandbox toggle is on, or the active key is a Test twin.
	 *
	 * @param bool   $sandbox_toggle Gateway testmode option.
	 * @param string $api_key        Active x-api-key.
	 * @return bool
	 */
	public static function debit_otp_is_simulated( bool $sandbox_toggle, string $api_key ): bool {
		if ( $sandbox_toggle ) {
			return true;
		}
		return 'test' === self::api_key_kind( $api_key );
	}

	/**
	 * Mask a phone for logs (keep last 4 digits).
	 *
	 * @param string $phone Raw or normalized phone.
	 * @return string
	 */
	public static function mask_phone_for_log( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone ) ?? '';
		if ( strlen( $digits ) < 4 ) {
			return '****';
		}
		return str_repeat( '*', strlen( $digits ) - 4 ) . substr( $digits, -4 );
	}

	/**
	 * Mask a phone for UI display with readable grouping (keep last 4 digits).
	 *
	 * Example: 584121233844 → ••• •••• 3844
	 *
	 * @param string $phone Raw or normalized phone.
	 * @return string
	 */
	public static function mask_phone_for_display( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone ) ?? '';
		if ( '' === $digits ) {
			return '';
		}
		if ( strlen( $digits ) < 4 ) {
			return $digits;
		}

		return '••• •••• ' . substr( $digits, -4 );
	}

	/**
	 * Compact shape summary for débito OTP request logs (no secrets).
	 *
	 * @param array $body Request body (banco, monto, telefono, cedula).
	 * @return string
	 */
	public static function describe_debit_otp_body( array $body ): string {
		$banco    = isset( $body['banco'] ) ? (string) $body['banco'] : '';
		$telefono = isset( $body['telefono'] ) ? (string) $body['telefono'] : '';
		$cedula   = isset( $body['cedula'] ) ? (string) $body['cedula'] : '';
		$monto    = isset( $body['monto'] ) ? $body['monto'] : null;
		$monto_s  = is_numeric( $monto ) ? number_format( (float) $monto, 2, '.', '' ) : 'n/a';

		return sprintf(
			'banco=%s(%s) monto=%s(%s) telefono=%s(len=%d) cedula=%s',
			'' !== $banco ? $banco : 'n/a',
			gettype( $body['banco'] ?? null ),
			$monto_s,
			gettype( $monto ),
			self::mask_phone_for_log( $telefono ),
			strlen( preg_replace( '/\D+/', '', $telefono ) ?? '' ),
			'' !== $cedula ? ( substr( $cedula, 0, 1 ) . str_repeat( '*', max( 0, strlen( $cedula ) - 1 ) ) ) : 'n/a'
		);
	}

	/**
	 * Whether a débito OTP response indicates the bank accepted the request.
	 *
	 * HTTP 2xx is checked by the API client; this inspects the wire `code` /
	 * `success` fields (typically `202` when the payer bank will SMS the OTP).
	 * OpenAPI marks `code` required — empty/missing code is not treated as success.
	 *
	 * @param array $result Decoded API body.
	 * @return bool
	 */
	public static function debit_otp_accepted( array $result ): bool {
		if ( array_key_exists( 'success', $result ) && false === $result['success'] ) {
			return false;
		}
		if ( ! isset( $result['code'] ) || '' === (string) $result['code'] ) {
			return false;
		}
		$code = strtoupper( (string) $result['code'] );
		return in_array( $code, array( '202', '00' ), true );
	}

	/**
	 * Human-readable error from a rejected débito OTP response.
	 *
	 * @param array $result Decoded API body.
	 * @return string
	 */
	public static function debit_otp_error_message( array $result ): string {
		if ( ! empty( $result['message'] ) && is_string( $result['message'] ) ) {
			return $result['message'];
		}
		if ( ! empty( $result['error'] ) && is_string( $result['error'] ) ) {
			return $result['error'];
		}
		$code = ! empty( $result['code'] ) ? (string) $result['code'] : '';
		if ( '' !== $code ) {
			return sprintf(
				/* translators: %s: API/business code */
				__( 'OTP request was not accepted (code %s).', 'woo-vexpay-gateway' ),
				$code
			);
		}
		return __( 'OTP request was not accepted.', 'woo-vexpay-gateway' );
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
