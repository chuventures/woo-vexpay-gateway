<?php
/**
 * WooCommerce payment gateway — VEXPay Tarjeta (VPOS / BNC).
 *
 * @package VEXPay_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Card gateway. VPOS is a capability of the same VEXPay account as Débito
 * inmediato, not a separate credential — this gateway reads the API key and
 * Sandbox toggle from the primary `vexpay` gateway's settings instead of
 * asking the merchant to paste their key a second time.
 */
class VEXPay_Gateway_VPOS extends WC_Payment_Gateway {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'vexpay_vpos';
		$this->method_title       = __( 'VEXPay — Tarjeta', 'vexpay-gateway-for-woocommerce' );
		$this->method_description = __( 'Accept Visa/Mastercard/Maestro charges via VEXPay VPOS (BNC). Uses the same API key as VEXPay Débito inmediato.', 'vexpay-gateway-for-woocommerce' );
		$this->has_fields         = true;
		$this->supports           = array( 'products' );
		$this->icon               = VEXPAY_GATEWAY_URL . 'assets/images/vexpay-logo.svg';

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Tarjeta (VEXPay)', 'vexpay-gateway-for-woocommerce' ) );
		$this->description = $this->get_option( 'description', __( 'Paga con tarjeta de crédito o débito.', 'vexpay-gateway-for-woocommerce' ) );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Admin fields. No API key fields here — shared with the primary gateway.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable/Disable', 'vexpay-gateway-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable VEXPay Tarjeta (VPOS)', 'vexpay-gateway-for-woocommerce' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Title', 'vexpay-gateway-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method title at checkout.', 'vexpay-gateway-for-woocommerce' ),
				'default'     => __( 'Tarjeta (VEXPay)', 'vexpay-gateway-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'       => __( 'Description', 'vexpay-gateway-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the payment method at checkout.', 'vexpay-gateway-for-woocommerce' ),
				'default'     => __( 'Paga con tarjeta de crédito o débito.', 'vexpay-gateway-for-woocommerce' ),
			),
			'shared_api_key' => array(
				'type'        => 'title',
				'title'       => __( 'API key', 'vexpay-gateway-for-woocommerce' ),
				'description' => __( 'This gateway shares the API key and Sandbox toggle configured under WooCommerce → Settings → Payments → VEXPay (Débito inmediato). There is nothing to configure here beyond enabling it.', 'vexpay-gateway-for-woocommerce' ),
			),
			'show_ves_quote' => array(
				'title'   => __( 'Show VES estimate', 'vexpay-gateway-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Display BCV VES amount at checkout (display only)', 'vexpay-gateway-for-woocommerce' ),
				'default' => 'yes',
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
	 * Only offer this method once the shared VEXPay API key is configured.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( ! parent::is_available() ) {
			return false;
		}
		return '' !== $this->get_active_api_key();
	}

	/**
	 * Classic checkout card form.
	 */
	public function payment_fields(): void {
		echo '<div class="vexpay-flow vexpay-checkout-panel">';
		echo '<div class="vexpay-checkout-brand">';
		echo '<img class="vexpay-checkout-brand__logo" src="' . esc_url( VEXPAY_GATEWAY_URL . 'assets/images/vexpay-logo.svg' ) . '" alt="VEXPay" width="72" height="38" decoding="async" />';
		echo '<span class="vexpay-checkout-brand__tag">' . esc_html__( 'Tarjeta', 'vexpay-gateway-for-woocommerce' ) . '</span>';
		echo '</div>';

		if ( $this->is_sandbox_toggle_on() ) {
			echo '<p class="vexpay-test-mode"><strong>' . esc_html__( 'SANDBOX', 'vexpay-gateway-for-woocommerce' ) . '</strong> — ';
			echo esc_html__( 'No real money moves here. Use a VEXPay sandbox test card.', 'vexpay-gateway-for-woocommerce' );
			echo '</p>';
		}

		if ( 'yes' === $this->get_option( 'show_ves_quote', 'yes' ) && function_exists( 'WC' ) && WC()->cart ) {
			$usd   = round( (float) WC()->cart->get_total( 'edit' ), 2 );
			$quote = $this->get_api_client()->get_quote( $usd );
			if ( ! is_wp_error( $quote ) && isset( $quote['vesAmount'], $quote['bcvRate'] ) ) {
				echo '<div class="vexpay-fx-strip">';
				echo '<span class="vexpay-fx-strip__ves">' . esc_html( sprintf( /* translators: %s: VES amount */ __( 'Bs. %s', 'vexpay-gateway-for-woocommerce' ), number_format_i18n( (float) $quote['vesAmount'], 2 ) ) ) . '</span>';
				echo '<span class="vexpay-fx-strip__rate">' . esc_html( sprintf( /* translators: %s: BCV FX rate */ __( 'BCV %s', 'vexpay-gateway-for-woocommerce' ), number_format_i18n( (float) $quote['bcvRate'], 4 ) ) ) . '</span>';
				echo '</div>';
			}
		}

		printf( '<fieldset id="wc-%1$s-cc-form" class="wc-payment-form vexpay-fields vexpay-card-details">', esc_attr( $this->id ) );

		echo '<p class="form-row form-row-wide vexpay-field-group">';
		echo '<label for="vexpay_vpos_card_number">' . esc_html__( 'Card number', 'vexpay-gateway-for-woocommerce' ) . '&nbsp;<abbr class="required" title="required">*</abbr></label>';
		echo '<input type="text" class="input-text vexpay-card-number" name="vexpay_vpos_card_number" id="vexpay_vpos_card_number" inputmode="numeric" autocomplete="cc-number" placeholder="4111 1111 1111 1111" maxlength="24" />';
		echo '</p>';

		echo '<p class="form-row form-row-first vexpay-field-group">';
		echo '<label for="vexpay_vpos_expiry">' . esc_html__( 'Expiry (MM/YY)', 'vexpay-gateway-for-woocommerce' ) . '&nbsp;<abbr class="required" title="required">*</abbr></label>';
		echo '<input type="text" class="input-text vexpay-card-expiry" name="vexpay_vpos_expiry" id="vexpay_vpos_expiry" inputmode="numeric" autocomplete="cc-exp" placeholder="MM/AA" maxlength="5" />';
		echo '</p>';

		echo '<p class="form-row form-row-last vexpay-field-group">';
		echo '<label for="vexpay_vpos_cvv">' . esc_html__( 'CVV', 'vexpay-gateway-for-woocommerce' ) . '&nbsp;<abbr class="required" title="required">*</abbr></label>';
		echo '<input type="text" class="input-text vexpay-card-cvv" name="vexpay_vpos_cvv" id="vexpay_vpos_cvv" inputmode="numeric" autocomplete="cc-csc" placeholder="123" maxlength="4" />';
		echo '</p>';

		echo '<p class="form-row form-row-first vexpay-field-group">';
		echo '<label for="vexpay_vpos_brand">' . esc_html__( 'Card brand', 'vexpay-gateway-for-woocommerce' ) . '&nbsp;<abbr class="required" title="required">*</abbr></label>';
		echo '<select name="vexpay_vpos_brand" id="vexpay_vpos_brand">';
		echo '<option value="">' . esc_html__( 'Select…', 'vexpay-gateway-for-woocommerce' ) . '</option>';
		foreach ( VEXPay_Helpers::card_brands() as $code => $label ) {
			printf( '<option value="%1$d">%2$s</option>', (int) $code, esc_html( $label ) );
		}
		echo '</select></p>';

		echo '<p class="form-row form-row-last vexpay-field-group">';
		echo '<label for="vexpay_vpos_account_type">' . esc_html__( 'Account type', 'vexpay-gateway-for-woocommerce' ) . '&nbsp;<abbr class="required" title="required">*</abbr></label>';
		echo '<select name="vexpay_vpos_account_type" id="vexpay_vpos_account_type">';
		foreach ( VEXPay_Helpers::card_account_types() as $code => $label ) {
			printf( '<option value="%1$d">%2$s</option>', (int) $code, esc_html( $label ) );
		}
		echo '</select></p>';

		echo '<p class="form-row form-row-wide vexpay-field-group">';
		echo '<label for="vexpay_vpos_holder_name">' . esc_html__( 'Cardholder name', 'vexpay-gateway-for-woocommerce' ) . '&nbsp;<abbr class="required" title="required">*</abbr></label>';
		echo '<input type="text" class="input-text" name="vexpay_vpos_holder_name" id="vexpay_vpos_holder_name" autocomplete="cc-name" />';
		echo '</p>';

		echo '<p class="form-row form-row-wide vexpay-field-group">';
		echo '<label for="vexpay_vpos_holder_id_number">' . esc_html__( 'Cardholder cédula / RIF', 'vexpay-gateway-for-woocommerce' ) . '&nbsp;<abbr class="required" title="required">*</abbr></label>';
		echo '<span class="vexpay-split-field">';
		echo '<select name="vexpay_vpos_holder_id_type" id="vexpay_vpos_holder_id_type" class="vexpay-split-prefix" aria-label="' . esc_attr__( 'Document type', 'vexpay-gateway-for-woocommerce' ) . '">';
		foreach ( VEXPay_Helpers::debtor_id_types() as $type ) {
			printf( '<option value="%1$s">%1$s</option>', esc_attr( $type ) );
		}
		echo '</select>';
		echo '<input type="text" class="input-text vexpay-split-input" name="vexpay_vpos_holder_id_number" id="vexpay_vpos_holder_id_number" inputmode="numeric" autocomplete="off" placeholder="12345678" maxlength="9" aria-label="' . esc_attr__( 'Document number', 'vexpay-gateway-for-woocommerce' ) . '" />';
		echo '</span></p>';

		echo '</fieldset>';
		echo '<p class="vexpay-checkout-secure">';
		echo '<span class="vexpay-checkout-secure__lock" aria-hidden="true"></span>';
		echo esc_html__( 'Secured by VEXPay', 'vexpay-gateway-for-woocommerce' );
		echo '</p>';
		echo '</div>';
	}

	/**
	 * Read + normalize the posted card form (works for classic + Blocks/Store API
	 * via VEXPay_Helpers::get_request_field()).
	 *
	 * @return array{card_number:?string,cvv:?string,expiry:?array,brand:?int,account_type:?int,holder_name:string,holder_id:?string}
	 */
	private function read_card_fields(): array {
		$holder_id_type   = VEXPay_Helpers::get_request_field( 'vexpay_vpos_holder_id_type' );
		$holder_id_number = VEXPay_Helpers::get_request_field( 'vexpay_vpos_holder_id_number' );

		return array(
			'card_number'  => VEXPay_Helpers::normalize_card_number( VEXPay_Helpers::get_request_field( 'vexpay_vpos_card_number' ) ),
			'cvv'          => VEXPay_Helpers::normalize_card_cvv( VEXPay_Helpers::get_request_field( 'vexpay_vpos_cvv' ) ),
			'expiry'       => $this->parse_expiry_field( VEXPay_Helpers::get_request_field( 'vexpay_vpos_expiry' ) ),
			'brand'        => VEXPay_Helpers::normalize_card_brand( VEXPay_Helpers::get_request_field( 'vexpay_vpos_brand' ) ),
			'account_type' => VEXPay_Helpers::normalize_card_account_type( VEXPay_Helpers::get_request_field( 'vexpay_vpos_account_type' ) ),
			'holder_name'  => trim( VEXPay_Helpers::get_request_field( 'vexpay_vpos_holder_name' ) ),
			'holder_id'    => VEXPay_Helpers::normalize_debtor_id( $holder_id_type . $holder_id_number ),
		);
	}

	/**
	 * Split a posted "MM/YY" (or "MMYY") expiry field into month/year.
	 *
	 * @param string $raw Raw posted value.
	 * @return array{month:int,year:int}|null
	 */
	private function parse_expiry_field( string $raw ): ?array {
		$parts = preg_split( '/[^0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		if ( 2 === count( $parts ) ) {
			return VEXPay_Helpers::normalize_card_expiry( $parts[0], $parts[1] );
		}
		$digits = preg_replace( '/\D+/', '', $raw ) ?? '';
		if ( strlen( $digits ) >= 3 ) {
			return VEXPay_Helpers::normalize_card_expiry( substr( $digits, 0, 2 ), substr( $digits, 2 ) );
		}
		return null;
	}

	/**
	 * Validate the posted card form.
	 *
	 * @return bool
	 */
	public function validate_fields(): bool {
		$fields = $this->read_card_fields();

		if ( ! $fields['card_number'] ) {
			wc_add_notice( __( 'Enter a valid card number.', 'vexpay-gateway-for-woocommerce' ), 'error' );
			return false;
		}
		if ( ! $fields['expiry'] ) {
			wc_add_notice( __( 'Enter a valid expiry date (MM/YY).', 'vexpay-gateway-for-woocommerce' ), 'error' );
			return false;
		}
		if ( ! $fields['cvv'] ) {
			wc_add_notice( __( 'Enter a valid CVV.', 'vexpay-gateway-for-woocommerce' ), 'error' );
			return false;
		}
		if ( null === $fields['brand'] ) {
			wc_add_notice( __( 'Select the card brand.', 'vexpay-gateway-for-woocommerce' ), 'error' );
			return false;
		}
		if ( null === $fields['account_type'] ) {
			wc_add_notice( __( 'Select the account type.', 'vexpay-gateway-for-woocommerce' ), 'error' );
			return false;
		}
		if ( strlen( $fields['holder_name'] ) < 2 ) {
			wc_add_notice( __( 'Enter the cardholder name.', 'vexpay-gateway-for-woocommerce' ), 'error' );
			return false;
		}
		if ( ! $fields['holder_id'] ) {
			wc_add_notice( __( 'Enter a valid cardholder cédula/RIF (e.g. V12345678).', 'vexpay-gateway-for-woocommerce' ), 'error' );
			return false;
		}

		return true;
	}

	/**
	 * Charge the card and settle the order — VPOS is synchronous, no OTP step.
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

		$fields = $this->read_card_fields();
		if ( ! $fields['card_number'] || ! $fields['expiry'] || ! $fields['cvv'] || null === $fields['brand'] || null === $fields['account_type'] || ! $fields['holder_id'] || strlen( $fields['holder_name'] ) < 2 ) {
			wc_add_notice( __( 'Invalid card details.', 'vexpay-gateway-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$usd          = VEXPay_Helpers::order_usd_amount( $order );
		$external_ref = VEXPay_Helpers::external_ref_for_order( (int) $order_id );

		// Never log $fields — it holds cardNumber/cvv in the clear.
		$result = $this->get_api_client()->execute_vpos(
			array(
				'usdAmount'       => $usd,
				'cardNumber'      => $fields['card_number'],
				'expirationMonth' => $fields['expiry']['month'],
				'expirationYear'  => $fields['expiry']['year'],
				'cvv'             => $fields['cvv'],
				'cardHolderName'  => $fields['holder_name'],
				'cardHolderId'    => $fields['holder_id'],
				'accountType'     => $fields['account_type'],
				'cardType'        => $fields['brand'],
				'externalRef'     => $external_ref,
			)
		);

		if ( is_wp_error( $result ) ) {
			$data        = $result->get_error_data();
			$code        = is_array( $data ) && is_array( $data['body'] ?? null ) ? ( $data['body']['code'] ?? '' ) : '';
			$status_code = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;

			if ( 'CARD_VELOCITY_BLOCKED' === $code || 429 === $status_code ) {
				$message = __( 'Too many card attempts. Please wait a while and try again, or use another payment method.', 'vexpay-gateway-for-woocommerce' );
			} else {
				$message = $result->get_error_message();
			}

			VEXPay_Logger::error( sprintf( 'VPOS charge failed for order %d: %s', $order_id, $message ) );
			wc_add_notice( $message, 'error' );
			$order->update_status( 'failed', 'VEXPay VPOS: ' . $message );
			return array( 'result' => 'failure' );
		}

		$this->apply_vpos_result( $order, $result );

		if ( ! $order->is_paid() ) {
			wc_add_notice( __( 'The card was not accepted. Try another card or payment method.', 'vexpay-gateway-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Apply the VPOS receipt to the order. `execute_vpos()` only returns a
	 * non-WP_Error result on a 2xx (COMPLETED) response — every failure mode
	 * (decline, carding block, etc.) comes back as WP_Error and is handled in
	 * process_payment() before this is called — so this only ever settles.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $result Receipt.
	 */
	private function apply_vpos_result( WC_Order $order, array $result ): void {
		if ( ! empty( $result['paymentId'] ) ) {
			$order->update_meta_data( VEXPay_Helpers::META_PAYMENT_ID, (string) $result['paymentId'] );
		}
		if ( ! empty( $result['bankReference'] ) ) {
			$order->set_transaction_id( (string) $result['bankReference'] );
		}
		if ( ! empty( $result['cardLast4'] ) ) {
			$order->update_meta_data( VEXPay_Helpers::META_CARD_LAST4, (string) $result['cardLast4'] );
		}
		if ( ! empty( $result['cardBrand'] ) ) {
			$order->update_meta_data( VEXPay_Helpers::META_CARD_BRAND, (string) $result['cardBrand'] );
		}

		$status = isset( $result['status'] ) ? strtoupper( (string) $result['status'] ) : 'COMPLETED';
		$order->update_meta_data( VEXPay_Helpers::META_STATUS, $status );

		if ( ! $order->is_paid() ) {
			$txn = ! empty( $result['bankReference'] ) ? (string) $result['bankReference'] : (string) ( $result['paymentId'] ?? '' );
			$order->payment_complete( $txn );
			$last4 = ! empty( $result['cardLast4'] ) ? ' ····' . (string) $result['cardLast4'] : '';
			$order->add_order_note(
				sprintf(
					/* translators: 1: payment id 2: masked card last 4 */
					__( 'VEXPay VPOS charge completed (%1$s)%2$s.', 'vexpay-gateway-for-woocommerce' ),
					(string) ( $result['paymentId'] ?? '' ),
					$last4
				)
			);
		}

		$order->save();
	}

	/**
	 * Process refund — no reverse API for VPOS charges yet.
	 *
	 * @param int        $order_id Order ID.
	 * @param float|null $amount   Amount.
	 * @param string     $reason   Reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		unset( $order_id, $amount, $reason );
		return new WP_Error(
			'vexpay_vpos_refund',
			__( 'Automatic refunds are not available for VEXPay Tarjeta yet. Handle the reversal with your bank/processor.', 'vexpay-gateway-for-woocommerce' )
		);
	}
}
