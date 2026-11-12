<?php
/**
 * Marketplace mode admin UI: "Comercio (VEX Pay)" product field and the order "VEX Pay split" box.
 *
 * @package VEXPay_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Marketplace admin.
 */
class VEXPay_Marketplace_Admin {

	/** The admin-post action that refreshes the cached comercio list. */
	public const REFRESH_ACTION = 'vexpay_refresh_merchants';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}

		// The order box also shows historical split orders after the mode is switched off.
		add_action( 'add_meta_boxes', array( __CLASS__, 'maybe_add_order_meta_box' ), 30, 2 );

		if ( ! VEXPay_Marketplace::is_enabled() ) {
			return;
		}

		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_field' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'render_variation_field' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_field' ), 10, 2 );
		add_action( 'admin_post_' . self::REFRESH_ACTION, array( __CLASS__, 'handle_refresh' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_refresh_notice' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Product / variation field.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Select options for the comercio field.
	 *
	 * @param string $current     Current merchant id.
	 * @param string $empty_label Label of the empty choice.
	 * @return array{options:array<string,string>,error:string}
	 */
	private static function field_options( string $current, string $empty_label ): array {
		$options   = array( '' => $empty_label );
		$error     = '';
		$merchants = VEXPay_Marketplace::get_merchants();
		if ( is_wp_error( $merchants ) ) {
			$error     = $merchants->get_error_message();
			$merchants = array();
		}

		foreach ( $merchants as $id => $merchant ) {
			$label = (string) $merchant['name'];
			if ( '' !== (string) $merchant['externalRef'] ) {
				$label .= ' · ' . (string) $merchant['externalRef'];
			}
			$options[ (string) $id ] = $label;
		}

		// Keep a saved id selectable even if it is no longer listed (inactive or unknown).
		if ( '' !== $current && ! isset( $options[ $current ] ) ) {
			$options[ $current ] = sprintf(
				/* translators: %s: merchant id */
				__( '%s (not active in VEX Pay)', 'vexpay-gateway-for-woocommerce' ),
				$current
			);
		}

		return array(
			'options' => $options,
			'error'   => $error,
		);
	}

	/**
	 * Refresh link (nonce-protected admin-post action).
	 *
	 * @return string
	 */
	private static function refresh_url(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::REFRESH_ACTION ), self::REFRESH_ACTION );
	}

	/**
	 * Print the refresh link and any load error.
	 *
	 * @param string $error Error message.
	 */
	private static function print_field_footer( string $error ): void {
		echo '<p class="form-field vexpay-comercio-help" style="margin-top:0">';
		if ( '' !== $error ) {
			echo '<span class="vexpay-comercio-error" style="color:#b32d2e">';
			echo esc_html(
				sprintf(
					/* translators: %s: API error */
					__( 'Could not load comercios from VEX Pay: %s', 'vexpay-gateway-for-woocommerce' ),
					$error
				)
			);
			echo '</span> ';
		}
		echo '<a href="' . esc_url( self::refresh_url() ) . '">' . esc_html__( 'Refresh comercio list', 'vexpay-gateway-for-woocommerce' ) . '</a>';
		echo '</p>';
	}

	/**
	 * "Comercio (VEX Pay)" select on the product General tab.
	 */
	public static function render_product_field(): void {
		global $post;
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}
		$product_id = $post ? (int) $post->ID : 0;
		$current    = VEXPay_Marketplace::product_merchant_id( $product_id );
		$choices    = self::field_options( $current, __( '— Marketplace (no comercio) —', 'vexpay-gateway-for-woocommerce' ) );

		echo '<div class="options_group vexpay-comercio-field">';
		woocommerce_wp_select(
			array(
				'id'          => VEXPay_Marketplace::PRODUCT_META,
				'label'       => __( 'Comercio (VEX Pay)', 'vexpay-gateway-for-woocommerce' ),
				'options'     => $choices['options'],
				'value'       => $current,
				'desc_tip'    => true,
				'description' => __( 'The VEX Pay comercio that receives this product\'s share of each payment. Variations can override it.', 'vexpay-gateway-for-woocommerce' ),
			)
		);
		self::print_field_footer( $choices['error'] );
		echo '</div>';
	}

	/**
	 * Save the product field. WooCommerce has already verified `woocommerce_meta_nonce`
	 * and the edit capability before this hook runs.
	 *
	 * @param WC_Product $product Product.
	 */
	public static function save_product_field( $product ): void {
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce (woocommerce_meta_nonce) before woocommerce_admin_process_product_object.
		if ( ! isset( $_POST[ VEXPay_Marketplace::PRODUCT_META ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- See above.
		$raw = sanitize_text_field( wp_unslash( $_POST[ VEXPay_Marketplace::PRODUCT_META ] ) );
		self::store_merchant_id( $product, $raw );
	}

	/**
	 * Per-variation override.
	 *
	 * @param int     $loop           Variation index.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Variation post.
	 */
	public static function render_variation_field( $loop, $variation_data, $variation ): void {
		unset( $variation_data );
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}
		$loop    = (int) $loop;
		$current = VEXPay_Marketplace::product_merchant_id( (int) $variation->ID );
		$choices = self::field_options( $current, __( '— Same as product —', 'vexpay-gateway-for-woocommerce' ) );

		woocommerce_wp_select(
			array(
				'id'            => VEXPay_Marketplace::PRODUCT_META . '_' . $loop,
				'name'          => VEXPay_Marketplace::PRODUCT_META . '[' . $loop . ']',
				'label'         => __( 'Comercio (VEX Pay)', 'vexpay-gateway-for-woocommerce' ),
				'options'       => $choices['options'],
				'value'         => $current,
				'wrapper_class' => 'form-row form-row-full',
			)
		);
		if ( '' !== $choices['error'] ) {
			self::print_field_footer( $choices['error'] );
		}
	}

	/**
	 * Save the per-variation override. WooCommerce verifies the `save-variations` nonce and
	 * the edit capability in its AJAX handler before this hook runs.
	 *
	 * @param int $variation_id Variation id.
	 * @param int $i            Variation index.
	 */
	public static function save_variation_field( $variation_id, $i ): void {
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce (save-variations) before woocommerce_save_product_variation.
		if ( ! isset( $_POST[ VEXPay_Marketplace::PRODUCT_META ] ) || ! is_array( $_POST[ VEXPay_Marketplace::PRODUCT_META ] ) ) {
			return;
		}
		$values = map_deep( wp_unslash( $_POST[ VEXPay_Marketplace::PRODUCT_META ] ), 'sanitize_text_field' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$i = (int) $i;
		if ( ! array_key_exists( $i, $values ) ) {
			return;
		}
		$variation = wc_get_product( (int) $variation_id );
		if ( ! $variation ) {
			return;
		}
		self::store_merchant_id( $variation, (string) $values[ $i ] );
		$variation->save();
	}

	/**
	 * Store or clear the merchant id on a product object (saved by the caller / WooCommerce).
	 *
	 * @param WC_Product $product Product.
	 * @param string     $raw     Raw value.
	 */
	private static function store_merchant_id( $product, string $raw ): void {
		$merchant_id = VEXPay_Marketplace::sanitize_merchant_id( $raw );
		if ( '' === $merchant_id ) {
			$product->delete_meta_data( VEXPay_Marketplace::PRODUCT_META );
			return;
		}
		$product->update_meta_data( VEXPay_Marketplace::PRODUCT_META, $merchant_id );
	}

	/**
	 * Handle the admin-post refresh: drop the cache, reload the list, go back.
	 */
	public static function handle_refresh(): void {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'vexpay-gateway-for-woocommerce' ), 403 );
		}
		check_admin_referer( self::REFRESH_ACTION );

		VEXPay_Marketplace::flush_merchants_cache();
		$result = VEXPay_Marketplace::get_merchants( true );

		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'edit.php?post_type=product' );
		}
		wp_safe_redirect( add_query_arg( 'vexpay_merchants', is_wp_error( $result ) ? 'error' : 'refreshed', $back ) );
		exit;
	}

	/**
	 * Notice after a refresh.
	 */
	public static function maybe_refresh_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag.
		$state = isset( $_GET['vexpay_merchants'] ) ? sanitize_key( wp_unslash( $_GET['vexpay_merchants'] ) ) : '';
		if ( '' === $state || ! current_user_can( 'edit_products' ) ) {
			return;
		}
		if ( 'refreshed' === $state ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'VEX Pay comercio list refreshed.', 'vexpay-gateway-for-woocommerce' ) . '</p></div>';
		} elseif ( 'error' === $state ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not refresh the VEX Pay comercio list. Check the API key and the debug log.', 'vexpay-gateway-for-woocommerce' ) . '</p></div>';
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Order screen box.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Add the "VEX Pay split" box on split orders (classic and HPOS order screens).
	 *
	 * @param string           $screen_id     Screen id.
	 * @param WP_Post|WC_Order $post_or_order Post or order.
	 */
	public static function maybe_add_order_meta_box( $screen_id, $post_or_order = null ): void {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		if ( ! in_array( $screen_id, $screens, true ) ) {
			return;
		}

		$order = self::resolve_order( $post_or_order );
		if ( ! $order || ! self::order_has_split( $order ) ) {
			return;
		}

		add_meta_box(
			'vexpay-split',
			__( 'VEX Pay split', 'vexpay-gateway-for-woocommerce' ),
			array( __CLASS__, 'render_order_meta_box' ),
			$screen_id,
			'normal',
			'default'
		);
	}

	/**
	 * Order from the meta box argument.
	 *
	 * @param mixed $post_or_order Post or order.
	 * @return WC_Order|null
	 */
	private static function resolve_order( $post_or_order ) {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}
		if ( $post_or_order instanceof WP_Post ) {
			$order = wc_get_order( $post_or_order->ID );
			return $order instanceof WC_Order ? $order : null;
		}
		return null;
	}

	/**
	 * Whether the order carries split data.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function order_has_split( $order ): bool {
		$record   = VEXPay_Marketplace::stored_record( $order );
		$reported = $order->get_meta( VEXPay_Marketplace::META_SPLITS_REPORTED );
		return ! empty( $record['splits'] ) || ( is_array( $reported ) && ! empty( $reported ) );
	}

	/**
	 * Box contents.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or order.
	 */
	public static function render_order_meta_box( $post_or_order ): void {
		$order = self::resolve_order( $post_or_order );
		if ( ! $order ) {
			return;
		}
		echo self::order_split_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in order_split_html().
	}

	/**
	 * Table rows for the box: one per comercio, plus the marketplace remainder.
	 *
	 * @param WC_Order $order Order.
	 * @return array{rows:array<int,array<string,string>>,currency:string,reported:bool,remainder:?float}
	 */
	public static function order_split_rows( $order ): array {
		$record   = VEXPay_Marketplace::stored_record( $order );
		$currency = isset( $record['currency'] ) ? (string) $record['currency'] : 'VES';
		$usd      = isset( $record['usd'] ) && is_array( $record['usd'] ) ? $record['usd'] : array();
		$sent     = isset( $record['splits'] ) && is_array( $record['splits'] ) ? $record['splits'] : array();
		$reported = $order->get_meta( VEXPay_Marketplace::META_SPLITS_REPORTED );
		$reported = is_array( $reported ) ? $reported : array();

		$by_id = array();
		foreach ( $reported as $share ) {
			if ( is_array( $share ) && ! empty( $share[ VEXPay_Marketplace::F_MERCHANT_ID ] ) ) {
				$by_id[ (string) $share[ VEXPay_Marketplace::F_MERCHANT_ID ] ] = $share;
			}
		}

		$merchants = VEXPay_Marketplace::cached_merchants();
		$ids       = array();
		foreach ( $sent as $share ) {
			if ( is_array( $share ) && ! empty( $share[ VEXPay_Marketplace::F_MERCHANT_ID ] ) ) {
				$ids[ (string) $share[ VEXPay_Marketplace::F_MERCHANT_ID ] ] = $share;
			}
		}
		foreach ( $by_id as $id => $share ) {
			if ( ! isset( $ids[ $id ] ) ) {
				$ids[ $id ] = array();
			}
		}

		$rows      = array();
		$sum_sent  = 0.0;
		$sum_valid = true;
		foreach ( $ids as $id => $share ) {
			$rep  = $by_id[ $id ] ?? array();
			$name = isset( $merchants[ $id ]['name'] ) ? (string) $merchants[ $id ]['name'] : '';
			if ( '' === $name && ! empty( $rep[ VEXPay_Marketplace::R_MERCHANT_EXTERNAL_REF ] ) ) {
				$name = (string) $rep[ VEXPay_Marketplace::R_MERCHANT_EXTERNAL_REF ];
			}

			$amount = isset( $share[ VEXPay_Marketplace::F_AMOUNT ] ) ? (float) $share[ VEXPay_Marketplace::F_AMOUNT ] : null;
			if ( null === $amount ) {
				$sum_valid = false;
			} else {
				$sum_sent += $amount;
			}

			$rows[] = array(
				'merchant_id' => (string) $id,
				'name'        => '' !== $name ? $name : (string) $id,
				'external'    => (string) ( $rep[ VEXPay_Marketplace::R_MERCHANT_EXTERNAL_REF ] ?? ( $merchants[ $id ]['externalRef'] ?? '' ) ),
				'amount'      => null === $amount ? '' : VEXPay_Marketplace::format_amount( $amount ),
				'usd'         => isset( $usd[ $id ] ) ? (string) $usd[ $id ] : '',
				'reference'   => (string) ( $share[ VEXPay_Marketplace::F_REFERENCE ] ?? ( $rep[ VEXPay_Marketplace::F_REFERENCE ] ?? '' ) ),
				'amount_ves'  => (string) ( $rep[ VEXPay_Marketplace::R_AMOUNT_VES ] ?? '' ),
				'commission'  => (string) ( $rep[ VEXPay_Marketplace::R_APPLICATION_FEE_VES ] ?? '' ),
				'fee'         => (string) ( $rep[ VEXPay_Marketplace::R_FEE_VES ] ?? '' ),
				'net'         => (string) ( $rep[ VEXPay_Marketplace::R_NET_VES ] ?? '' ),
			);
		}

		$remainder = null;
		if ( $sum_valid && ! empty( $sent ) ) {
			$total     = 'VES' === $currency ? (float) $order->get_meta( VEXPay_Helpers::META_VES_AMOUNT ) : round( (float) $order->get_total(), 2 );
			$remainder = $total > 0 ? round( $total - $sum_sent, 2 ) : null;
		}

		return array(
			'rows'      => $rows,
			'currency'  => $currency,
			'reported'  => ! empty( $by_id ),
			'remainder' => $remainder,
		);
	}

	/**
	 * Format an amount for display with its currency.
	 *
	 * @param string $amount   Amount.
	 * @param string $currency VES|USD.
	 * @return string Plain text (escape on output).
	 */
	private static function money( string $amount, string $currency ): string {
		if ( '' === $amount || ! is_numeric( $amount ) ) {
			return '—';
		}
		$formatted = function_exists( 'number_format_i18n' ) ? number_format_i18n( (float) $amount, 2 ) : number_format( (float) $amount, 2 );
		return ( 'USD' === $currency ? 'US$' : 'Bs ' ) . $formatted;
	}

	/**
	 * Box HTML (every dynamic value escaped).
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function order_split_html( $order ): string {
		$data = self::order_split_rows( $order );
		if ( empty( $data['rows'] ) ) {
			return '<p>' . esc_html__( 'No split on this order.', 'vexpay-gateway-for-woocommerce' ) . '</p>';
		}

		$html  = '<table class="widefat striped vexpay-split-table"><thead><tr>';
		$heads = array(
			__( 'Comercio', 'vexpay-gateway-for-woocommerce' ),
			__( 'Share', 'vexpay-gateway-for-woocommerce' ),
			__( 'Reference', 'vexpay-gateway-for-woocommerce' ),
			__( 'Commission', 'vexpay-gateway-for-woocommerce' ),
			__( 'VEX Pay fee', 'vexpay-gateway-for-woocommerce' ),
			__( 'Net', 'vexpay-gateway-for-woocommerce' ),
		);
		foreach ( $heads as $head ) {
			$html .= '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';

		foreach ( $data['rows'] as $row ) {
			$share = self::money( $row['amount'], $data['currency'] );
			if ( 'VES' === $data['currency'] && '' !== $row['usd'] ) {
				$share .= ' (' . self::money( $row['usd'], 'USD' ) . ')';
			} elseif ( 'USD' === $data['currency'] && '' !== $row['amount_ves'] ) {
				$share .= ' (' . self::money( $row['amount_ves'], 'VES' ) . ')';
			}

			$html .= '<tr>';
			$html .= '<td><strong>' . esc_html( $row['name'] ) . '</strong><br /><code>' . esc_html( $row['merchant_id'] ) . '</code>';
			if ( '' !== $row['external'] && $row['external'] !== $row['name'] ) {
				$html .= '<br /><small>' . esc_html( $row['external'] ) . '</small>';
			}
			$html .= '</td>';
			$html .= '<td>' . esc_html( $share ) . '</td>';
			$html .= '<td>' . esc_html( '' !== $row['reference'] ? $row['reference'] : '—' ) . '</td>';
			$html .= '<td>' . esc_html( self::money( $row['commission'], 'VES' ) ) . '</td>';
			$html .= '<td>' . esc_html( self::money( $row['fee'], 'VES' ) ) . '</td>';
			$html .= '<td>' . esc_html( self::money( $row['net'], 'VES' ) ) . '</td>';
			$html .= '</tr>';
		}

		if ( null !== $data['remainder'] ) {
			$html .= '<tr><td><em>' . esc_html__( 'Marketplace (shipping, fees, unmapped items, rounding)', 'vexpay-gateway-for-woocommerce' ) . '</em></td>';
			$html .= '<td>' . esc_html( self::money( VEXPay_Marketplace::format_amount( (float) $data['remainder'] ), $data['currency'] ) ) . '</td>';
			$html .= '<td colspan="4"></td></tr>';
		}

		$html .= '</tbody></table>';

		if ( ! $data['reported'] ) {
			$html .= '<p class="description">' . esc_html__( 'Commission, VEX Pay fee and net appear once VEX Pay reports the payment.', 'vexpay-gateway-for-woocommerce' ) . '</p>';
		}

		return $html;
	}
}
