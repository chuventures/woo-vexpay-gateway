<?php
/**
 * Marketplace mode: split one payment between VEX Pay comercios (merchants).
 *
 * Off by default. While the "Marketplace splits" setting is off, nothing in this
 * class changes a request, an order or the checkout.
 *
 * @package VEXPay_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Marketplace splits.
 */
class VEXPay_Marketplace {
	/*
	 * ---------------------------------------------------------------------
	 * VEX Pay wire field names. Kept in one place so they are easy to adjust
	 * if the API contract changes.
	 * ---------------------------------------------------------------------
	 */

	/** Request/response key that carries the list of comercio shares. */
	public const F_SPLITS = 'splits';
	/** Share: VEX Pay merchant id (UUID or mrc_…). */
	public const F_MERCHANT_ID = 'merchantId';
	/** Share: amount as a "0.00" string in the request's amount currency. */
	public const F_AMOUNT = 'amount';
	/** Share: explicit marketplace commission in VES (optional). */
	public const F_APPLICATION_FEE_VES = 'applicationFeeVes';
	/** Share: explicit marketplace commission percent (optional). */
	public const F_APPLICATION_FEE_PERCENT = 'applicationFeePercent';
	/** Share: free text returned as sent (optional, max 64 chars). */
	public const F_REFERENCE = 'reference';

	/** Reported share (responses / webhooks): store's own comercio id. */
	public const R_MERCHANT_EXTERNAL_REF = 'merchantExternalRef';
	/** Reported share: share amount in VES. */
	public const R_AMOUNT_VES = 'amountVes';
	/** Reported share: marketplace commission in VES. */
	public const R_APPLICATION_FEE_VES = 'applicationFeeVes';
	/** Reported share: VEX Pay fee allocated to the share, in VES. */
	public const R_FEE_VES = 'feeVes';
	/** Reported share: what the comercio is credited, in VES. */
	public const R_NET_VES = 'netVes';

	/** Maximum shares per payment (one per comercio). */
	public const MAX_SPLITS = 10;

	/** Max length of a share reference. */
	public const MAX_REFERENCE_LENGTH = 64;

	/** Payment methods that can carry splits. */
	public const METHOD_DEBIT = 'debit';
	public const METHOD_VPOS  = 'vpos';

	/*
	 * ---------------------------------------------------------------------
	 * Store-side keys.
	 * ---------------------------------------------------------------------
	 */

	/** Setting on the primary `vexpay` gateway. */
	public const SETTING_KEY = 'marketplace_splits';

	/** Product / variation meta: VEX Pay merchant id of the comercio. */
	public const PRODUCT_META = '_vexpay_merchant_id';

	/** Order meta: the shares the plugin computed and sent. */
	public const META_SPLITS = '_vexpay_splits';

	/** Order meta: the shares VEX Pay reported back (commission, fee, net). */
	public const META_SPLITS_REPORTED = '_vexpay_splits_reported';

	/** Transient prefix for the cached merchant list (suffix: md5 of the active key). */
	public const MERCHANTS_TRANSIENT = 'vexpay_merchants_';

	/** Merchant list cache lifetime (seconds). */
	public const MERCHANTS_TTL = 600;

	/** Page size for GET /v1/merchants (API max 100). */
	public const MERCHANTS_PAGE_SIZE = 100;

	/** Safety cap on merchant list pages. */
	public const MERCHANTS_MAX_PAGES = 20;

	/**
	 * Generic customer-facing message when a split cannot be honored.
	 *
	 * @return string
	 */
	public static function customer_refusal_message(): string {
		return __( 'Este plan no está disponible para pago en este momento', 'vexpay-gateway-for-woocommerce' );
	}

	/**
	 * Primary gateway settings (API key, sandbox toggle, marketplace setting).
	 *
	 * @return array
	 */
	private static function settings(): array {
		$settings = get_option( 'woocommerce_vexpay_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Whether marketplace mode is on.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$settings = self::settings();
		return isset( $settings[ self::SETTING_KEY ] ) && 'yes' === $settings[ self::SETTING_KEY ];
	}

	/**
	 * Active API key from the shared settings (never printed or logged).
	 *
	 * @return string
	 */
	private static function active_api_key(): string {
		$settings = self::settings();
		$sandbox  = ! isset( $settings['testmode'] ) || 'yes' === $settings['testmode'];
		$key      = $sandbox ? ( $settings['test_api_key'] ?? '' ) : ( $settings['live_api_key'] ?? '' );
		return (string) $key;
	}

	/**
	 * API client for the shared settings.
	 *
	 * @return VEXPay_API_Client
	 */
	public static function api_client(): VEXPay_API_Client {
		return new VEXPay_API_Client( VEXPAY_GATEWAY_API_BASE_URL, self::active_api_key() );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Amount helpers.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Round down to 0.01 (guarding against binary float error, e.g. 2190.0 stored as 2189.9999999).
	 *
	 * @param float $value Value.
	 * @return float
	 */
	public static function floor2( float $value ): float {
		return floor( round( $value * 100, 6 ) ) / 100;
	}

	/**
	 * Wire format for an amount: "0.00".
	 *
	 * @param float $value Value.
	 * @return string
	 */
	public static function format_amount( float $value ): string {
		return number_format( $value, 2, '.', '' );
	}

	/**
	 * Whether a merchant id looks valid (UUID or mrc_… id).
	 *
	 * @param mixed $merchant_id Candidate.
	 * @return bool
	 */
	public static function is_valid_merchant_id( $merchant_id ): bool {
		return is_string( $merchant_id ) && 1 === preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', $merchant_id );
	}

	/**
	 * Clean a merchant id from user input / meta / filters.
	 *
	 * @param mixed $merchant_id Candidate.
	 * @return string Valid id or ''.
	 */
	public static function sanitize_merchant_id( $merchant_id ): string {
		if ( ! is_scalar( $merchant_id ) ) {
			return '';
		}
		$merchant_id = trim( (string) $merchant_id );
		return self::is_valid_merchant_id( $merchant_id ) ? $merchant_id : '';
	}

	/*
	 * ---------------------------------------------------------------------
	 * Line-item resolution.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Comercio stored on a product or variation.
	 *
	 * @param int $product_id Product or variation id.
	 * @return string
	 */
	public static function product_merchant_id( int $product_id ): string {
		if ( $product_id <= 0 ) {
			return '';
		}
		return self::sanitize_merchant_id( get_post_meta( $product_id, self::PRODUCT_META, true ) );
	}

	/**
	 * Comercio from the product fields: the variation's, else the parent product's.
	 *
	 * @param int $product_id   Parent product id.
	 * @param int $variation_id Variation id (0 when none).
	 * @return string
	 */
	public static function field_merchant_id( int $product_id, int $variation_id ): string {
		$merchant_id = self::product_merchant_id( $variation_id );
		if ( '' === $merchant_id ) {
			$merchant_id = self::product_merchant_id( $product_id );
		}
		return $merchant_id;
	}

	/**
	 * Resolve the comercio of an order line item.
	 *
	 * Order: the `vexpay_line_item_merchant_id` filter, then the variation's field,
	 * then the parent product's field, then none (the line belongs to the marketplace).
	 *
	 * @param WC_Order_Item_Product $item  Line item.
	 * @param WC_Order              $order Order.
	 * @return string Merchant id or ''.
	 */
	public static function line_item_merchant_id( $item, $order ): string {
		$merchant_id = self::field_merchant_id( (int) $item->get_product_id(), (int) $item->get_variation_id() );

		/**
		 * Supply the VEX Pay merchant id of an order line item's comercio.
		 *
		 * Return '' to leave the line with the marketplace. The value returned here
		 * wins over the "Comercio (VEX Pay)" product field.
		 *
		 * @param string                $merchant_id Merchant id from the product field ('' when none).
		 * @param WC_Order_Item_Product $item        Line item.
		 * @param WC_Order              $order       Order.
		 */
		$merchant_id = apply_filters( 'vexpay_line_item_merchant_id', $merchant_id, $item, $order );

		return self::sanitize_merchant_id( $merchant_id );
	}

	/**
	 * Resolve the comercio of a cart item (used to hide methods that cannot split).
	 *
	 * @param array      $cart_item Cart item.
	 * @param WC_Product $product   Product.
	 * @return string
	 */
	public static function cart_item_merchant_id( array $cart_item, $product ): string {
		$merchant_id = self::field_merchant_id(
			(int) ( $cart_item['product_id'] ?? 0 ),
			(int) ( $cart_item['variation_id'] ?? 0 )
		);

		/**
		 * Supply the VEX Pay merchant id of a cart item's comercio. Hook this together
		 * with `vexpay_line_item_merchant_id` when your store keeps its own comercio records,
		 * so checkout can hide methods that cannot split (USDT).
		 *
		 * @param string     $merchant_id Merchant id from the product field ('' when none).
		 * @param array      $cart_item   Cart item.
		 * @param WC_Product $product     Product.
		 */
		$merchant_id = apply_filters( 'vexpay_cart_item_merchant_id', $merchant_id, $cart_item, $product );

		return self::sanitize_merchant_id( $merchant_id );
	}

	/**
	 * Order lines as plain arrays: [ merchant_id, total (after discounts), tax, name ].
	 *
	 * Only product lines are returned. Shipping and fee lines always stay with the marketplace.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int, array{merchant_id:string,total:float,tax:float,name:string}>
	 */
	public static function lines_from_order( $order ): array {
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$lines[] = array(
				'merchant_id' => self::line_item_merchant_id( $item, $order ),
				'total'       => (float) $item->get_total(),
				'tax'         => (float) $item->get_total_tax(),
				'name'        => (string) $item->get_name(),
			);
		}
		return $lines;
	}

	/**
	 * Whether the order holds at least one comercio item.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function order_has_comercio_items( $order ): bool {
		if ( ! self::is_enabled() ) {
			return false;
		}
		foreach ( self::lines_from_order( $order ) as $line ) {
			if ( '' !== $line['merchant_id'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the current cart holds at least one comercio item.
	 *
	 * @return bool
	 */
	public static function cart_has_comercio_items(): bool {
		if ( ! self::is_enabled() || ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;
			if ( '' !== self::cart_item_merchant_id( $cart_item, $product ) ) {
				return true;
			}
		}
		return false;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Share computation (pure).
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Group lines per comercio and compute each share.
	 *
	 * - Share in the order currency (USD) = sum of the comercio's `line_total + line_tax`, rounded to cents.
	 * - Shipping, fees and unmapped lines are not shares: they form the marketplace remainder.
	 * - If the shares would exceed the payment (e.g. a negative fee line), they are scaled down pro rata.
	 * - Débito: each share is converted with the order's own quote, rounded DOWN to 0.01:
	 *   floor2( shareUsd × vesTotal / usdTotal ).
	 * - VPOS (sent with `usdAmount`): shares are sent in USD.
	 *
	 * @param array  $lines     Output of {@see lines_from_order()}.
	 * @param string $method    METHOD_DEBIT or METHOD_VPOS.
	 * @param float  $usd_total Payment amount in USD (order total).
	 * @param float  $ves_total Payment amount in VES (débito quote; ignored for VPOS).
	 * @param string $reference Optional reference copied onto each share.
	 * @return array{splits:array<int,array<string,string>>,usd:array<string,string>}|WP_Error
	 */
	public static function compute_shares( array $lines, string $method, float $usd_total, float $ves_total = 0.0, string $reference = '' ) {
		$per_merchant = array();
		foreach ( $lines as $line ) {
			$merchant_id = isset( $line['merchant_id'] ) ? (string) $line['merchant_id'] : '';
			if ( '' === $merchant_id ) {
				continue;
			}
			if ( ! isset( $per_merchant[ $merchant_id ] ) ) {
				$per_merchant[ $merchant_id ] = 0.0;
			}
			$per_merchant[ $merchant_id ] += (float) ( $line['total'] ?? 0 ) + (float) ( $line['tax'] ?? 0 );
		}

		$usd_shares = array();
		foreach ( $per_merchant as $merchant_id => $amount ) {
			$amount = round( $amount, 2 );
			if ( $amount > 0 ) {
				$usd_shares[ (string) $merchant_id ] = $amount;
			}
		}

		if ( empty( $usd_shares ) ) {
			return array(
				'splits' => array(),
				'usd'    => array(),
			);
		}

		if ( count( $usd_shares ) > self::MAX_SPLITS ) {
			return new WP_Error(
				'vexpay_split_too_many',
				sprintf(
					/* translators: 1: comercio count 2: maximum */
					__( 'The order has %1$d comercios; VEX Pay splits a payment between at most %2$d.', 'vexpay-gateway-for-woocommerce' ),
					count( $usd_shares ),
					self::MAX_SPLITS
				)
			);
		}

		$usd_total = round( $usd_total, 2 );
		if ( $usd_total <= 0 ) {
			return new WP_Error( 'vexpay_split_total', __( 'Payment amount must be positive to split it.', 'vexpay-gateway-for-woocommerce' ) );
		}

		$sum = array_sum( $usd_shares );
		if ( $sum > $usd_total + 0.000001 ) {
			$scale = $usd_total / $sum;
			foreach ( $usd_shares as $merchant_id => $amount ) {
				$usd_shares[ $merchant_id ] = self::floor2( $amount * $scale );
			}
		}

		$is_debit = self::METHOD_DEBIT === $method;
		if ( $is_debit && $ves_total <= 0 ) {
			return new WP_Error( 'vexpay_split_quote', __( 'Missing VES quote to convert the split.', 'vexpay-gateway-for-woocommerce' ) );
		}

		$splits = array();
		$usd    = array();
		foreach ( $usd_shares as $merchant_id => $amount ) {
			$wire_amount = $is_debit ? self::floor2( $amount * $ves_total / $usd_total ) : $amount;
			if ( $wire_amount <= 0 ) {
				continue;
			}
			$share = array(
				self::F_MERCHANT_ID => (string) $merchant_id,
				self::F_AMOUNT      => self::format_amount( $wire_amount ),
			);
			if ( '' !== $reference ) {
				$share[ self::F_REFERENCE ] = self::truncate_reference( $reference );
			}
			$splits[]                     = $share;
			$usd[ (string) $merchant_id ] = self::format_amount( $amount );
		}

		return array(
			'splits' => $splits,
			'usd'    => $usd,
		);
	}

	/**
	 * Validate and normalize a share list (after the `vexpay_order_splits` filter).
	 *
	 * @param mixed $splits    Candidate list.
	 * @param float $max_total Payment amount in the share currency.
	 * @return array<int,array<string,string|float>>|WP_Error
	 */
	public static function sanitize_splits( $splits, float $max_total ) {
		if ( ! is_array( $splits ) ) {
			return new WP_Error( 'vexpay_split_invalid', __( 'Splits must be a list.', 'vexpay-gateway-for-woocommerce' ) );
		}

		$clean = array();
		$seen  = array();
		$sum   = 0.0;
		foreach ( array_values( $splits ) as $index => $share ) {
			if ( ! is_array( $share ) ) {
				return new WP_Error( 'vexpay_split_invalid', sprintf( 'splits[%d] is not an object.', $index ) );
			}
			$merchant_id = self::sanitize_merchant_id( $share[ self::F_MERCHANT_ID ] ?? '' );
			if ( '' === $merchant_id ) {
				return new WP_Error( 'vexpay_split_invalid', sprintf( 'splits[%d].%s is missing or invalid.', $index, self::F_MERCHANT_ID ) );
			}
			if ( isset( $seen[ $merchant_id ] ) ) {
				return new WP_Error( 'vexpay_split_invalid', sprintf( 'splits[%d]: comercio %s appears twice.', $index, $merchant_id ) );
			}
			$seen[ $merchant_id ] = true;

			$raw_amount = $share[ self::F_AMOUNT ] ?? null;
			if ( ! is_numeric( $raw_amount ) || (float) $raw_amount <= 0 ) {
				return new WP_Error( 'vexpay_split_invalid', sprintf( 'splits[%d].%s must be a positive amount.', $index, self::F_AMOUNT ) );
			}
			$amount = self::floor2( (float) $raw_amount );
			if ( $amount <= 0 ) {
				return new WP_Error( 'vexpay_split_invalid', sprintf( 'splits[%d].%s must be at least 0.01.', $index, self::F_AMOUNT ) );
			}
			$sum += $amount;

			$entry = array(
				self::F_MERCHANT_ID => $merchant_id,
				self::F_AMOUNT      => self::format_amount( $amount ),
			);

			$has_fee_ves     = isset( $share[ self::F_APPLICATION_FEE_VES ] ) && '' !== $share[ self::F_APPLICATION_FEE_VES ];
			$has_fee_percent = isset( $share[ self::F_APPLICATION_FEE_PERCENT ] ) && '' !== $share[ self::F_APPLICATION_FEE_PERCENT ];
			if ( $has_fee_ves && $has_fee_percent ) {
				return new WP_Error( 'vexpay_split_invalid', sprintf( 'splits[%d] sets both commission fields; use one.', $index ) );
			}
			if ( $has_fee_ves ) {
				$fee = $share[ self::F_APPLICATION_FEE_VES ];
				if ( ! is_numeric( $fee ) || (float) $fee < 0 ) {
					return new WP_Error( 'vexpay_split_invalid', sprintf( 'splits[%d].%s must be a non-negative amount.', $index, self::F_APPLICATION_FEE_VES ) );
				}
				$entry[ self::F_APPLICATION_FEE_VES ] = self::format_amount( (float) $fee );
			}
			if ( $has_fee_percent ) {
				$pct = $share[ self::F_APPLICATION_FEE_PERCENT ];
				if ( ! is_numeric( $pct ) || (float) $pct < 0 || (float) $pct > 100 ) {
					return new WP_Error( 'vexpay_split_invalid', sprintf( 'splits[%d].%s must be between 0 and 100.', $index, self::F_APPLICATION_FEE_PERCENT ) );
				}
				$entry[ self::F_APPLICATION_FEE_PERCENT ] = round( (float) $pct, 4 );
			}
			if ( isset( $share[ self::F_REFERENCE ] ) && is_scalar( $share[ self::F_REFERENCE ] ) && '' !== (string) $share[ self::F_REFERENCE ] ) {
				$entry[ self::F_REFERENCE ] = self::truncate_reference( (string) $share[ self::F_REFERENCE ] );
			}

			$clean[] = $entry;
		}

		if ( count( $clean ) > self::MAX_SPLITS ) {
			return new WP_Error(
				'vexpay_split_too_many',
				sprintf(
					/* translators: %d: maximum */
					__( 'VEX Pay splits a payment between at most %d comercios.', 'vexpay-gateway-for-woocommerce' ),
					self::MAX_SPLITS
				)
			);
		}

		if ( round( $sum, 2 ) > round( $max_total, 2 ) ) {
			return new WP_Error(
				'vexpay_split_sum',
				sprintf( 'Shares sum to %s, above the payment amount %s.', self::format_amount( $sum ), self::format_amount( $max_total ) )
			);
		}

		return $clean;
	}

	/**
	 * Shorten a reference to the API maximum.
	 *
	 * @param string $reference Reference.
	 * @return string
	 */
	private static function truncate_reference( string $reference ): string {
		$reference = trim( $reference );
		return function_exists( 'mb_substr' ) ? mb_substr( $reference, 0, self::MAX_REFERENCE_LENGTH ) : substr( $reference, 0, self::MAX_REFERENCE_LENGTH );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Order integration.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Compute, filter, validate and store the order's shares for a payment method.
	 *
	 * Returns an empty array (and stores nothing) when marketplace mode is off or the order
	 * has no comercio item. Returns WP_Error when the split cannot be honored; the caller
	 * must then refuse the payment (never pay without splits).
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $method    METHOD_DEBIT or METHOD_VPOS.
	 * @param float    $usd_total Payment amount in USD.
	 * @param float    $ves_total Payment amount in VES (débito).
	 * @return array|WP_Error Wire shares.
	 */
	public static function prepare_order_splits( $order, string $method, float $usd_total, float $ves_total = 0.0 ) {
		if ( ! self::is_enabled() ) {
			return array();
		}

		$reference = VEXPay_Helpers::external_ref_for_order( (int) $order->get_id() );
		$computed  = self::compute_shares( self::lines_from_order( $order ), $method, $usd_total, $ves_total, $reference );
		if ( is_wp_error( $computed ) ) {
			return $computed;
		}

		/**
		 * Replace or adjust the shares sent with a payment, e.g. to add a per-comercio commission
		 * (`applicationFeePercent` or `applicationFeeVes`).
		 *
		 * Amounts are "0.00" strings in VES for débito and in USD for VPOS. Return [] to send no split.
		 *
		 * @param array    $splits Shares: [ { merchantId, amount, reference? } ].
		 * @param WC_Order $order  Order.
		 * @param string   $method 'debit' or 'vpos'.
		 */
		$filtered = apply_filters( 'vexpay_order_splits', $computed['splits'], $order, $method );

		$max_total = self::METHOD_DEBIT === $method ? $ves_total : $usd_total;
		$splits    = self::sanitize_splits( $filtered, $max_total );
		if ( is_wp_error( $splits ) ) {
			return $splits;
		}

		if ( empty( $splits ) ) {
			$order->delete_meta_data( self::META_SPLITS );
			return array();
		}

		$order->update_meta_data(
			self::META_SPLITS,
			array(
				'method'   => $method,
				'currency' => self::METHOD_DEBIT === $method ? 'VES' : 'USD',
				'usd'      => $computed['usd'],
				'splits'   => $splits,
			)
		);
		$order->delete_meta_data( self::META_SPLITS_REPORTED );

		return $splits;
	}

	/**
	 * Stored record from {@see prepare_order_splits()}.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function stored_record( $order ): array {
		$record = $order->get_meta( self::META_SPLITS );
		return is_array( $record ) ? $record : array();
	}

	/**
	 * Add the stored shares to a request body. With marketplace mode off, or no stored
	 * shares for this method, the body is returned unchanged (no `splits` key).
	 *
	 * @param array    $body   Request body.
	 * @param WC_Order $order  Order.
	 * @param string   $method METHOD_DEBIT or METHOD_VPOS.
	 * @return array
	 */
	public static function with_splits( array $body, $order, string $method ): array {
		if ( ! self::is_enabled() ) {
			return $body;
		}
		$record = self::stored_record( $order );
		if ( ( $record['method'] ?? '' ) !== $method || empty( $record['splits'] ) || ! is_array( $record['splits'] ) ) {
			return $body;
		}
		$body[ self::F_SPLITS ] = array_values( $record['splits'] );
		return $body;
	}

	/**
	 * Whether an API error is VEX Pay refusing the split (a 400 naming `splits`).
	 *
	 * @param mixed $error Result of an API call.
	 * @return bool
	 */
	public static function is_split_rejection( $error ): bool {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}
		$data   = $error->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
		if ( 400 !== $status ) {
			return false;
		}
		$haystack = $error->get_error_message();
		if ( is_array( $data ) && isset( $data['body'] ) ) {
			$encoded   = function_exists( 'wp_json_encode' ) ? wp_json_encode( $data['body'] ) : json_encode( $data['body'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			$haystack .= ' ' . (string) $encoded;
		}
		return false !== stripos( $haystack, self::F_SPLITS );
	}

	/**
	 * Detail text of a split rejection (API message, joined when it is a list).
	 *
	 * @param WP_Error $error Error.
	 * @return string
	 */
	public static function rejection_detail( $error ): string {
		$data = $error->get_error_data();
		$body = is_array( $data ) && is_array( $data['body'] ?? null ) ? $data['body'] : array();
		if ( isset( $body['message'] ) && is_array( $body['message'] ) ) {
			return implode( '; ', array_map( 'strval', array_filter( $body['message'], 'is_scalar' ) ) );
		}
		return $error->get_error_message();
	}

	/**
	 * Record a refused split on the order: order note naming the comercios, log entry,
	 * order marked failed. The caller shows {@see customer_refusal_message()} and must
	 * NOT retry without splits.
	 *
	 * @param WC_Order $order  Order.
	 * @param mixed    $error  WP_Error from the API or from local validation.
	 * @param string   $method METHOD_DEBIT or METHOD_VPOS.
	 */
	public static function record_refusal( $order, $error, string $method ): void {
		$detail = is_wp_error( $error ) ? self::rejection_detail( $error ) : '';
		$names  = self::describe_comercios( $order );

		$note = sprintf(
			/* translators: 1: payment method 2: API / validation detail 3: comercio list */
			__( 'VEX Pay split refused (%1$s): %2$s. Comercios: %3$s. No payment was taken, and the payment was not retried without splits.', 'vexpay-gateway-for-woocommerce' ),
			$method,
			'' !== $detail ? $detail : __( 'unknown reason', 'vexpay-gateway-for-woocommerce' ),
			'' !== $names ? $names : __( 'none', 'vexpay-gateway-for-woocommerce' )
		);

		VEXPay_Logger::always_error( sprintf( 'Split refused for order %d (%s): %s | comercios: %s', $order->get_id(), $method, $detail, $names ) );

		$order->update_meta_data( VEXPay_Helpers::META_STATUS, 'FAILED' );
		$order->update_status( 'failed', $note );
		$order->save();
	}

	/**
	 * "Name (merchantId)" list of the order's comercios, from the stored split or the lines.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function describe_comercios( $order ): string {
		$ids    = array();
		$record = self::stored_record( $order );
		if ( ! empty( $record['splits'] ) && is_array( $record['splits'] ) ) {
			foreach ( $record['splits'] as $share ) {
				if ( is_array( $share ) && ! empty( $share[ self::F_MERCHANT_ID ] ) ) {
					$ids[] = (string) $share[ self::F_MERCHANT_ID ];
				}
			}
		} else {
			foreach ( self::lines_from_order( $order ) as $line ) {
				if ( '' !== $line['merchant_id'] ) {
					$ids[] = $line['merchant_id'];
				}
			}
		}
		$ids = array_values( array_unique( $ids ) );

		$merchants = self::cached_merchants();
		$labels    = array();
		foreach ( $ids as $id ) {
			$name     = isset( $merchants[ $id ]['name'] ) ? (string) $merchants[ $id ]['name'] : '';
			$labels[] = '' !== $name ? sprintf( '%s (%s)', $name, $id ) : $id;
		}
		return implode( ', ', $labels );
	}

	/**
	 * Store the per-share breakdown VEX Pay reports in a payment response or webhook.
	 * No-op when the payload has no `splits`.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Payment receipt / webhook data.
	 * @param bool     $save  Save the order.
	 * @return bool Whether anything was stored.
	 */
	public static function capture_reported_splits( $order, array $data, bool $save = true ): bool {
		$reported = self::normalize_reported_splits( $data[ self::F_SPLITS ] ?? null );
		if ( empty( $reported ) ) {
			return false;
		}
		$order->update_meta_data( self::META_SPLITS_REPORTED, $reported );
		if ( $save ) {
			$order->save();
		}
		return true;
	}

	/**
	 * Keep only the known fields of reported shares.
	 *
	 * @param mixed $splits Reported list.
	 * @return array<int,array<string,string>>
	 */
	public static function normalize_reported_splits( $splits ): array {
		if ( ! is_array( $splits ) ) {
			return array();
		}
		$fields = array(
			self::F_MERCHANT_ID,
			self::R_MERCHANT_EXTERNAL_REF,
			self::R_AMOUNT_VES,
			self::R_APPLICATION_FEE_VES,
			self::R_FEE_VES,
			self::R_NET_VES,
			self::F_REFERENCE,
		);
		$out    = array();
		foreach ( $splits as $share ) {
			if ( ! is_array( $share ) || empty( $share[ self::F_MERCHANT_ID ] ) ) {
				continue;
			}
			$row = array();
			foreach ( $fields as $field ) {
				if ( isset( $share[ $field ] ) && is_scalar( $share[ $field ] ) ) {
					$row[ $field ] = (string) $share[ $field ];
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Comercio list (GET /v1/merchants), cached.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Transient name for the active key (keys never stored in clear in option names).
	 *
	 * @return string
	 */
	private static function merchants_transient(): string {
		return self::MERCHANTS_TRANSIENT . md5( self::active_api_key() );
	}

	/**
	 * Cached merchant list only (no API call).
	 *
	 * @return array<string,array{name:string,externalRef:string}>
	 */
	public static function cached_merchants(): array {
		$cached = get_transient( self::merchants_transient() );
		return is_array( $cached ) ? $cached : array();
	}

	/**
	 * Drop the cached merchant list.
	 */
	public static function flush_merchants_cache(): void {
		delete_transient( self::merchants_transient() );
	}

	/**
	 * Active merchants of the tenant, keyed by merchant id. Cached 10 minutes.
	 *
	 * @param bool                   $force  Skip the cache.
	 * @param VEXPay_API_Client|null $client Client (tests).
	 * @return array<string,array{name:string,externalRef:string}>|WP_Error
	 */
	public static function get_merchants( bool $force = false, $client = null ) {
		if ( ! $force ) {
			$cached = get_transient( self::merchants_transient() );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$client    = $client ? $client : self::api_client();
		$merchants = array();
		$cursor    = '';
		for ( $page = 0; $page < self::MERCHANTS_MAX_PAGES; $page++ ) {
			$query = array(
				'isActive' => 'true',
				'limit'    => self::MERCHANTS_PAGE_SIZE,
			);
			if ( '' !== $cursor ) {
				$query['cursor'] = $cursor;
			}
			$result = $client->list_merchants( $query );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			foreach ( self::normalize_merchant_list( $result ) as $id => $merchant ) {
				$merchants[ $id ] = $merchant;
			}
			$cursor = isset( $result['nextCursor'] ) && is_string( $result['nextCursor'] ) ? $result['nextCursor'] : '';
			if ( '' === $cursor ) {
				break;
			}
		}

		set_transient( self::merchants_transient(), $merchants, self::MERCHANTS_TTL );
		return $merchants;
	}

	/**
	 * Normalize a GET /v1/merchants page.
	 *
	 * @param mixed $result API body (`items` list, or a bare list).
	 * @return array<string,array{name:string,externalRef:string}>
	 */
	public static function normalize_merchant_list( $result ): array {
		if ( ! is_array( $result ) ) {
			return array();
		}
		$list = $result['items'] ?? ( $result['data'] ?? $result );
		if ( ! is_array( $list ) ) {
			return array();
		}
		$out = array();
		foreach ( $list as $merchant ) {
			if ( ! is_array( $merchant ) ) {
				continue;
			}
			$id = self::sanitize_merchant_id( $merchant['merchantId'] ?? ( $merchant['id'] ?? '' ) );
			if ( '' === $id ) {
				continue;
			}
			if ( isset( $merchant['isActive'] ) && false === $merchant['isActive'] ) {
				continue;
			}
			$name = '';
			foreach ( array( 'displayName', 'name' ) as $field ) {
				if ( ! empty( $merchant[ $field ] ) && is_string( $merchant[ $field ] ) ) {
					$name = $merchant[ $field ];
					break;
				}
			}
			$out[ $id ] = array(
				'name'        => '' !== $name ? $name : $id,
				'externalRef' => isset( $merchant['externalRef'] ) && is_scalar( $merchant['externalRef'] ) ? (string) $merchant['externalRef'] : '',
			);
		}
		return $out;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Comercio helper: create or update a VEX Pay merchant from a store record.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Create or update a VEX Pay merchant keyed by the store's comercio id (`externalRef`).
	 *
	 * Accepted keys:
	 * - external_ref (required): the store's own comercio id.
	 * - name, identification (V/E + 8 digits or J/G + 9), contact_email, contact_phone: required to create.
	 * - bank_code + phone: Pago Móvil payout account (optional; a new account needs micro-deposit verification).
	 * - application_fee_percent: marketplace commission % (null = tenant default).
	 * - auto_payout: bool.
	 *
	 * @param array                  $comercio Store record.
	 * @param VEXPay_API_Client|null $client   Client (tests).
	 * @return string|WP_Error Merchant id.
	 */
	public static function upsert_merchant( array $comercio, $client = null ) {
		$external_ref = isset( $comercio['external_ref'] ) && is_scalar( $comercio['external_ref'] ) ? trim( (string) $comercio['external_ref'] ) : '';
		if ( '' === $external_ref ) {
			return new WP_Error( 'vexpay_merchant_external_ref', __( 'external_ref (your comercio id) is required.', 'vexpay-gateway-for-woocommerce' ) );
		}

		$client = $client ? $client : self::api_client();

		$bank_code = isset( $comercio['bank_code'] ) && is_scalar( $comercio['bank_code'] ) ? VEXPay_Helpers::format_simf_bank_code( (string) $comercio['bank_code'] ) : '';
		$phone     = isset( $comercio['phone'] ) && is_scalar( $comercio['phone'] ) ? preg_replace( '/\D+/', '', (string) $comercio['phone'] ) : '';

		$create = array(
			'externalRef'    => $external_ref,
			'name'           => isset( $comercio['name'] ) ? trim( (string) $comercio['name'] ) : '',
			'identification' => isset( $comercio['identification'] ) ? strtoupper( (string) preg_replace( '/[\s\-\.]/', '', (string) $comercio['identification'] ) ) : '',
			'contactEmail'   => isset( $comercio['contact_email'] ) ? trim( (string) $comercio['contact_email'] ) : '',
			'contactPhone'   => isset( $comercio['contact_phone'] ) ? trim( (string) $comercio['contact_phone'] ) : '',
		);
		if ( '' !== $bank_code && '' !== $phone ) {
			$create['bankCode'] = $bank_code;
			$create['phone']    = $phone;
		}

		$merchant = $client->create_merchant( $create );

		if ( is_wp_error( $merchant ) ) {
			$data   = $merchant->get_error_data();
			$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
			if ( 409 !== $status ) {
				return $merchant;
			}

			// Same externalRef, different data: look it up and update what can change.
			$merchant = $client->get_merchant_by_external_ref( $external_ref );
			if ( is_wp_error( $merchant ) ) {
				return $merchant;
			}

			$merchant_id = self::sanitize_merchant_id( $merchant['merchantId'] ?? ( $merchant['id'] ?? '' ) );
			if ( '' === $merchant_id ) {
				return new WP_Error( 'vexpay_merchant_lookup', __( 'VEX Pay did not return a merchant id.', 'vexpay-gateway-for-woocommerce' ) );
			}

			if ( '' !== $bank_code && '' !== $phone && ! self::merchant_has_payout_method( $merchant, $bank_code, $phone ) ) {
				$method = $client->add_merchant_payout_method(
					$merchant_id,
					array(
						'bankCode' => $bank_code,
						'phone'    => $phone,
					)
				);
				if ( is_wp_error( $method ) ) {
					return $method;
				}
				VEXPay_Logger::info( sprintf( 'Comercio %s: new payout account added; it needs micro-deposit verification.', $external_ref ) );
			}
		} else {
			$merchant_id = self::sanitize_merchant_id( $merchant['merchantId'] ?? ( $merchant['id'] ?? '' ) );
			if ( '' === $merchant_id ) {
				return new WP_Error( 'vexpay_merchant_create', __( 'VEX Pay did not return a merchant id.', 'vexpay-gateway-for-woocommerce' ) );
			}
		}

		$patch = array();
		if ( array_key_exists( 'application_fee_percent', $comercio ) ) {
			$pct                            = $comercio['application_fee_percent'];
			$patch['applicationFeePercent'] = ( null === $pct || '' === $pct ) ? null : round( (float) $pct, 4 );
		}
		if ( array_key_exists( 'auto_payout', $comercio ) ) {
			$patch['autoPayoutEnabled'] = (bool) $comercio['auto_payout'];
		}
		if ( array_key_exists( 'is_active', $comercio ) ) {
			$patch['isActive'] = (bool) $comercio['is_active'];
		}
		if ( ! empty( $patch ) ) {
			$updated = $client->update_merchant( $merchant_id, $patch );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		self::flush_merchants_cache();
		return $merchant_id;
	}

	/**
	 * Whether the merchant already has this Pago Móvil payout account.
	 *
	 * @param array  $merchant  MerchantResponseDto.
	 * @param string $bank_code Bank code (4 digits).
	 * @param string $phone     Phone digits.
	 * @return bool
	 */
	private static function merchant_has_payout_method( array $merchant, string $bank_code, string $phone ): bool {
		$methods = isset( $merchant['payoutMethods'] ) && is_array( $merchant['payoutMethods'] ) ? $merchant['payoutMethods'] : array();
		if ( empty( $methods ) && ! empty( $merchant['bankCode'] ) ) {
			$methods[] = array(
				'bankCode'    => $merchant['bankCode'],
				'destination' => $merchant['destination'] ?? '',
			);
		}
		$want = substr( $phone, -10 );
		foreach ( $methods as $method ) {
			if ( ! is_array( $method ) ) {
				continue;
			}
			$have_bank  = VEXPay_Helpers::format_simf_bank_code( (string) ( $method['bankCode'] ?? '' ) );
			$have_phone = substr( (string) preg_replace( '/\D+/', '', (string) ( $method['destination'] ?? '' ) ), -10 );
			if ( $have_bank === $bank_code && '' !== $have_phone && $have_phone === $want ) {
				return true;
			}
		}
		return false;
	}
}
