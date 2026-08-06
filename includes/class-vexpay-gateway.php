<?php
/**
 * WooCommerce payment gateway — VEXPay Débito inmediato.
 *
 * @package VEXPay_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gateway.
 */
class VEXPay_Gateway extends WC_Payment_Gateway {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'vexpay';
		$this->method_title       = __( 'VEXPay', 'woo-vexpay-gateway' );
		$this->method_description = __( 'Accept Venezuela Débito inmediato payments via VEXPay (bank OTP).', 'woo-vexpay-gateway' );
		$this->has_fields         = true;
		$this->supports           = array( 'products', 'refunds' );
		$this->icon               = VEXPAY_GATEWAY_URL . 'assets/images/vexpay-logo.svg';

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Débito inmediato (VEXPay)', 'woo-vexpay-gateway' ) );
		$this->description = $this->get_option( 'description', __( 'Pay from your bank account with an SMS OTP.', 'woo-vexpay-gateway' ) );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'maybe_register_webhook' ) );

		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_api_vexpay_otp', array( $this, 'handle_otp_submit' ) );
		add_action( 'woocommerce_api_vexpay_otp_resend', array( $this, 'handle_otp_resend' ) );
		add_action( 'woocommerce_api_vexpay_otp_form', array( $this, 'render_otp_form_page' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_otp_notice' ) );
		add_action( 'template_redirect', array( $this, 'maybe_redirect_order_pay_to_otp' ), 20 );
		add_filter( 'woocommerce_valid_order_statuses_for_payment', array( $this, 'valid_statuses_for_otp_payment' ), 10, 2 );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( $this, 'valid_statuses_for_otp_payment' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
	}

	/**
	 * Allow order-pay / OTP while the C2P intent is on-hold.
	 *
	 * WooCommerce only treats pending/failed as payable by default, which blocks
	 * the receipt OTP form after we create the intent.
	 *
	 * @param string[]      $statuses Statuses.
	 * @param WC_Order|null $order    Order.
	 * @return string[]
	 */
	public function valid_statuses_for_otp_payment( $statuses, $order = null ): array {
		$statuses = is_array( $statuses ) ? $statuses : array();
		if ( ! $order instanceof WC_Order || $this->id !== $order->get_payment_method() ) {
			return $statuses;
		}

		// Still waiting on bank OTP — keep order-pay available.
		$status = strtoupper( (string) $order->get_meta( VEXPay_Helpers::META_STATUS ) );
		if ( in_array( $status, array( '', 'PENDING' ), true ) && ! $order->is_paid() ) {
			$statuses[] = 'on-hold';
		}

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Keep password-type settings when left blank on save.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$post_data = $this->get_post_data();
		$secrets   = array( 'live_api_key', 'test_api_key', 'webhook_secret' );

		foreach ( $secrets as $key ) {
			$field = 'woocommerce_' . $this->id . '_' . $key;
			if ( empty( $post_data[ $field ] ) ) {
				$_POST[ $field ] = $this->get_option( $key ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
		}

		return parent::process_admin_options();
	}

	/**
	 * Admin fields.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'vexpay_logo'         => array(
				'type' => 'vexpay_logo',
			),
			'enabled'             => array(
				'title'   => __( 'Enable/Disable', 'woo-vexpay-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable VEXPay C2P', 'woo-vexpay-gateway' ),
				'default' => 'no',
			),
			'title'               => array(
				'title'       => __( 'Title', 'woo-vexpay-gateway' ),
				'type'        => 'text',
				'description' => __( 'Payment method title at checkout.', 'woo-vexpay-gateway' ),
				'default'     => __( 'C2P / Pago móvil (VEXPay)', 'woo-vexpay-gateway' ),
				'desc_tip'    => true,
			),
			'description'         => array(
				'title'       => __( 'Description', 'woo-vexpay-gateway' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the payment method at checkout.', 'woo-vexpay-gateway' ),
				'default'     => __( 'Pay with your bank C2P token (OTP). You will confirm with a code from your banking app.', 'woo-vexpay-gateway' ),
			),
			'testmode'            => array(
				'title'       => __( 'Sandbox', 'woo-vexpay-gateway' ),
				'type'        => 'vexpay_toggle',
				'default'     => 'yes',
				'description' => __( 'When on, use the sandbox API key and sandbox OTP. Turn off for production payments.', 'woo-vexpay-gateway' ),
			),
			'live_api_key'        => array(
				'title'       => __( 'API key', 'woo-vexpay-gateway' ),
				'type'        => 'password',
				'class'       => 'vexpay-api-key-field vexpay-api-key-production',
				'description' => __( 'x-api-key from the VEXPay dashboard. Never share publicly.', 'woo-vexpay-gateway' ),
				'default'     => '',
			),
			'test_api_key'        => array(
				'title'       => __( 'API key', 'woo-vexpay-gateway' ),
				'type'        => 'password',
				'class'       => 'vexpay-api-key-field vexpay-api-key-sandbox',
				'description' => __( 'Sandbox x-api-key from the VEXPay dashboard.', 'woo-vexpay-gateway' ),
				'default'     => '',
			),
			'connection_test'     => array(
				'title'       => __( 'Connection', 'woo-vexpay-gateway' ),
				'type'        => 'vexpay_connection_test',
				'description' => __( 'Verify that this WordPress site can reach VEXPay with the API key for the mode selected above. Paste the key, then click Test — you can test before saving.', 'woo-vexpay-gateway' ),
			),
			'webhook_secret'      => array(
				'title'       => __( 'Webhook secret', 'woo-vexpay-gateway' ),
				'type'        => 'password',
				'description' => __( 'HMAC secret for X-Webhook-Signature. Filled automatically when the plugin registers a webhook on a public URL, or paste from the VEXPay dashboard. Localhost is skipped (use a tunnel).', 'woo-vexpay-gateway' ),
				'default'     => '',
			),
			'webhook_endpoint_id' => array(
				'title'       => __( 'Webhook endpoint ID', 'woo-vexpay-gateway' ),
				'type'        => 'text',
				'description' => __( 'Stored after automatic registration. Leave blank to re-register on save.', 'woo-vexpay-gateway' ),
				'default'     => '',
			),
			'show_ves_quote'      => array(
				'title'   => __( 'Show VES estimate', 'woo-vexpay-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Display BCV VES amount at checkout (display only)', 'woo-vexpay-gateway' ),
				'default' => 'yes',
			),
			'debug'               => array(
				'title'       => __( 'Debug log', 'woo-vexpay-gateway' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable logging', 'woo-vexpay-gateway' ),
				'default'     => 'no',
				'description' => sprintf(
					/* translators: %s: WooCommerce logs URL path hint */
					__( 'Logs appear under WooCommerce → Status → Logs (source: vexpay). Never log API keys.', 'woo-vexpay-gateway' )
				),
			),
		);
	}

	/**
	 * Active API key.
	 *
	 * @return string
	 */
	public function get_active_api_key(): string {
		if ( 'yes' === $this->get_option( 'testmode', 'yes' ) ) {
			return (string) $this->get_option( 'test_api_key', '' );
		}
		return (string) $this->get_option( 'live_api_key', '' );
	}

	/**
	 * API client for current settings.
	 *
	 * @return VEXPay_API_Client
	 */
	public function get_api_client(): VEXPay_API_Client {
		return new VEXPay_API_Client( VEXPAY_GATEWAY_API_BASE_URL, $this->get_active_api_key() );
	}

	/**
	 * Webhook URL for this shop.
	 *
	 * @return string
	 */
	public function get_webhook_url(): string {
		return WC()->api_request_url( 'vexpay_webhook' );
	}

	/**
	 * Custom settings row: on/off toggle (no Test/Live labels).
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field config.
	 * @return string
	 */
	public function generate_vexpay_toggle_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$data      = wp_parse_args(
			$data,
			array(
				'title'       => '',
				'description' => '',
				'default'     => 'no',
				'disabled'    => false,
			)
		);

		$checked = 'yes' === $this->get_option( $key, $data['default'] );

		ob_start();
		?>
		<tr valign="top" class="vexpay-toggle-row">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo esc_html( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<label class="vexpay-toggle">
						<input
							name="<?php echo esc_attr( $field_key ); ?>"
							id="<?php echo esc_attr( $field_key ); ?>"
							type="checkbox"
							value="1"
							class="vexpay-toggle-input"
							<?php checked( $checked ); ?>
							<?php disabled( $data['disabled'], true ); ?>
						/>
						<span class="vexpay-toggle-track" aria-hidden="true">
							<span class="vexpay-toggle-thumb"></span>
						</span>
						<span class="screen-reader-text"><?php echo esc_html( $data['title'] ); ?></span>
					</label>
					<?php if ( ! empty( $data['description'] ) ) : ?>
						<p class="description"><?php echo esc_html( $data['description'] ); ?></p>
					<?php endif; ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Persist custom toggle fields as yes/no (WooCommerce checkbox convention).
	 *
	 * @param string $key Field key.
	 * @param mixed  $value Posted value.
	 * @return string
	 */
	public function validate_vexpay_toggle_field( $key, $value ) {
		return ! empty( $value ) ? 'yes' : 'no';
	}

	/**
	 * Custom settings row: Test connection button.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field config.
	 * @return string
	 */
	public function generate_vexpay_connection_test_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$data      = wp_parse_args(
			$data,
			array(
				'title'       => '',
				'description' => '',
			)
		);

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<button type="button" class="button button-secondary" id="vexpay-test-connection">
						<?php esc_html_e( 'Test connection', 'woo-vexpay-gateway' ); ?>
					</button>
					<span class="spinner" id="vexpay-test-connection-spinner" style="float:none;margin:0 8px;visibility:hidden;"></span>
					<p class="description"><?php echo esc_html( $data['description'] ); ?></p>
					<div id="vexpay-test-connection-result" class="vexpay-connection-result" aria-live="polite"></div>
				</fieldset>
			</td>
		</tr>
		<?php
		unset( $field_key );
		return (string) ob_get_clean();
	}

	/**
	 * Custom settings row: VEXPay logo.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field config.
	 * @return string
	 */
	public function generate_vexpay_logo_html( $key, $data ) {
		ob_start();
		?>
		<tr valign="top">
			<td colspan="2" class="forminp vexpay-admin-logo-cell">
				<img src="<?php echo esc_url( VEXPAY_GATEWAY_URL . 'assets/images/vexpay-logo.svg' ); ?>" alt="<?php echo esc_attr( __( 'VEXPay', 'woo-vexpay-gateway' ) ); ?>" class="vexpay-admin-logo" />
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Whether the current admin screen is this gateway's settings section.
	 */
	private function is_gateway_settings_screen(): bool {
		if ( ! is_admin() ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';

		return 'wc-settings' === $page && 'checkout' === $tab && $this->id === $section;
	}

	/**
	 * Body class so CSS can hide the inactive API key row before JS runs.
	 *
	 * @param string $classes Space-separated classes.
	 * @return string
	 */
	public function admin_body_class( $classes ) {
		if ( ! $this->is_gateway_settings_screen() ) {
			return $classes;
		}

		$classes .= 'yes' === $this->get_option( 'testmode', 'yes' )
			? ' vexpay-sandbox-on'
			: ' vexpay-sandbox-off';

		return $classes;
	}

	/**
	 * Admin assets on the VEXPay gateway settings screen.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue_admin_assets( $hook ): void {
		if ( 'woocommerce_page_wc-settings' !== $hook || ! $this->is_gateway_settings_screen() ) {
			return;
		}

		wp_enqueue_style(
			'vexpay-admin',
			VEXPAY_GATEWAY_URL . 'assets/css/admin.css',
			array(),
			VEXPAY_GATEWAY_VERSION
		);

		wp_enqueue_script(
			'vexpay-admin',
			VEXPAY_GATEWAY_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			VEXPAY_GATEWAY_VERSION,
			true
		);

		wp_localize_script(
			'vexpay-admin',
			'vexpayAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'vexpay_test_connection' ),
				'i18n'    => array(
					'testing' => __( 'Testing…', 'woo-vexpay-gateway' ),
					'failed'  => __( 'Connection failed.', 'woo-vexpay-gateway' ),
				),
			)
		);
	}

	/**
	 * AJAX: probe VEXPay with the key for the selected mode.
	 */
	public function ajax_test_connection(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to test the connection.', 'woo-vexpay-gateway' ) ),
				403
			);
		}

		check_ajax_referer( 'vexpay_test_connection', 'nonce' );

		$base = untrailingslashit( VEXPAY_GATEWAY_API_BASE_URL );

		$testmode = isset( $_POST['testmode'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['testmode'] ) );

		$posted_live = isset( $_POST['live_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['live_api_key'] ) ) : '';
		$posted_test = isset( $_POST['test_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['test_api_key'] ) ) : '';

		// Password inputs are empty unless retyped — fall back to saved keys.
		$live_key = '' !== $posted_live ? $posted_live : (string) $this->get_option( 'live_api_key', '' );
		$test_key = '' !== $posted_test ? $posted_test : (string) $this->get_option( 'test_api_key', '' );
		$api_key  = $testmode ? $test_key : $live_key;

		if ( '' === $api_key ) {
			wp_send_json_error(
				array(
					'message' => __( 'Enter an API key (or save one first), then try again.', 'woo-vexpay-gateway' ),
				)
			);
		}

		$client = new VEXPay_API_Client( $base, $api_key, 15 );
		$result = $client->test_connection();

		if ( is_wp_error( $result ) ) {
			$error_data = $result->get_error_data();
			$status     = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 0;
			$message    = $result->get_error_message();
			if ( 401 === $status || 403 === $status ) {
				$message = __( 'API key rejected (unauthorized). Confirm the key matches Sandbox on/off and is active in the VEXPay dashboard.', 'woo-vexpay-gateway' );
			}
			VEXPay_Logger::error( 'Connection test failed: ' . $message );
			wp_send_json_error(
				array(
					'message' => $message,
					'mode'    => $testmode ? 'test' : 'live',
					'baseUrl' => $base,
				)
			);
		}

		VEXPay_Logger::info( 'Connection test OK (' . ( $testmode ? 'test' : 'live' ) . ')' );
		wp_send_json_success(
			array(
				'message'   => $result['message'],
				'mode'      => $testmode ? 'test' : 'live',
				'baseUrl'   => $base,
				'bankCount' => $result['bank_count'] ?? 0,
				'bcvRate'   => $result['bcv_rate'] ?? null,
			)
		);
	}

	/**
	 * Register or update webhook on settings save.
	 */
	public function maybe_register_webhook(): void {
		if ( 'yes' !== $this->enabled ) {
			return;
		}

		$key = $this->get_active_api_key();
		if ( '' === $key ) {
			return;
		}

		$client = $this->get_api_client();
		$url    = untrailingslashit( $this->get_webhook_url() );
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		// Hosted VEXPay cannot reach local wp-env / private hosts — skip auto-register.
		if ( is_string( $host ) && preg_match( '/^(localhost|127\.0\.0\.1|\[::1\]|.*\.local)$/i', $host ) ) {
			VEXPay_Logger::info( 'Skipped webhook registration for local URL: ' . $url );
			return;
		}

		$events   = array(
			'payment.pending',
			'payment.completed',
			'payment.failed',
			'payment.canceled',
			'payment.reversed',
		);
		$endpoint = (string) $this->get_option( 'webhook_endpoint_id', '' );
		$secret   = (string) $this->get_option( 'webhook_secret', '' );

		if ( '' !== $endpoint ) {
			$result = $client->update_webhook(
				$endpoint,
				array(
					'url'      => $url,
					'events'   => $events,
					'isActive' => true,
				)
			);
			if ( is_wp_error( $result ) ) {
				VEXPay_Logger::error( 'Webhook update failed: ' . $result->get_error_message() );
			}
			return;
		}

		$result = $client->create_webhook( $url, $events, $secret );
		if ( is_wp_error( $result ) ) {
			VEXPay_Logger::error( 'Webhook create failed: ' . $result->get_error_message() );
			return;
		}

		if ( ! empty( $result['id'] ) ) {
			$this->update_option( 'webhook_endpoint_id', (string) $result['id'] );
		}
		if ( ! empty( $result['secret'] ) ) {
			$this->update_option( 'webhook_secret', (string) $result['secret'] );
		}

		VEXPay_Logger::info( 'Webhook registered at ' . $url );
	}

	/**
	 * Checkout fields (classic).
	 */
	public function payment_fields(): void {
		echo '<div class="vexpay-flow">';
		$this->render_checkout_steps( 1 );

		if ( 'yes' === $this->get_option( 'testmode', 'yes' ) ) {
			echo '<p class="vexpay-test-mode"><strong>' . esc_html__( 'SANDBOX', 'woo-vexpay-gateway' ) . '</strong> — ';
			echo esc_html__( "it's giving demo energy. No real money moves here — practice all you want.", 'woo-vexpay-gateway' );
			echo '</p>';
		}

		echo '<div class="vexpay-step-panel">';
		echo '<h3 class="vexpay-step-title">' . esc_html__( 'Step 1 — Your details', 'woo-vexpay-gateway' ) . '</h3>';
		echo '<p class="vexpay-step-copy">' . esc_html__( 'Tell us who is paying. When you place the order we text a bank OTP — then you’ll enter it in step 2.', 'woo-vexpay-gateway' ) . '</p>';

		if ( $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}

		$banks = $this->fetch_banks_list();

		if ( 'yes' === $this->get_option( 'show_ves_quote', 'yes' ) && WC()->cart ) {
			$usd   = round( (float) WC()->cart->get_total( 'edit' ), 2 );
			$quote = $this->get_api_client()->get_quote( $usd );
			if ( ! is_wp_error( $quote ) && isset( $quote['vesAmount'], $quote['bcvRate'] ) ) {
				echo '<p class="vexpay-ves-quote">';
				echo esc_html(
					sprintf(
						/* translators: 1: VES amount 2: BCV rate */
						__( 'Estimated VES (BCV): Bs. %1$s (rate %2$s)', 'woo-vexpay-gateway' ),
						number_format_i18n( (float) $quote['vesAmount'], 2 ),
						number_format_i18n( (float) $quote['bcvRate'], 4 )
					)
				);
				echo '</p>';
			}
		}

		$profile          = $this->get_debtor_profile();
		$debtor_id_type   = $profile['id_type'];
		$debtor_id_number = $profile['id_number'];
		$phone_prefix     = $profile['phone_prefix'];
		$phone_number     = $profile['phone_number'];
		$debtor_bank      = $profile['bank'];

		echo '<fieldset id="wc-' . esc_attr( $this->id ) . '-cc-form" class="wc-payment-form vexpay-fields">';

		echo '<p class="form-row form-row-wide vexpay-field-group">';
		echo '<label for="vexpay_debtor_id_number">' . esc_html__( 'Cédula / RIF', 'woo-vexpay-gateway' ) . '&nbsp;<abbr class="required" title="required">*</abbr></label>';
		echo '<span class="vexpay-split-field">';
		echo '<select name="vexpay_debtor_id_type" id="vexpay_debtor_id_type" class="vexpay-split-prefix" aria-label="' . esc_attr__( 'Document type', 'woo-vexpay-gateway' ) . '">';
		foreach ( VEXPay_Helpers::debtor_id_types() as $type ) {
			printf(
				'<option value="%1$s" %2$s>%1$s</option>',
				esc_attr( $type ),
				selected( strtoupper( $debtor_id_type ), $type, false )
			);
		}
		echo '</select>';
		printf(
			'<input type="text" class="input-text vexpay-split-input" name="vexpay_debtor_id_number" id="vexpay_debtor_id_number" inputmode="numeric" autocomplete="off" placeholder="12345678" maxlength="9" value="%s" aria-label="%s" />',
			esc_attr( preg_replace( '/\D+/', '', $debtor_id_number ) ?? '' ),
			esc_attr__( 'Document number', 'woo-vexpay-gateway' )
		);
		echo '</span></p>';

		echo '<p class="form-row form-row-wide vexpay-field-group">';
		echo '<label for="vexpay_debtor_phone_number">' . esc_html__( 'Phone (Pago Móvil)', 'woo-vexpay-gateway' ) . '&nbsp;<abbr class="required" title="required">*</abbr></label>';
		echo '<span class="vexpay-split-field">';
		echo '<select name="vexpay_debtor_phone_prefix" id="vexpay_debtor_phone_prefix" class="vexpay-split-prefix" aria-label="' . esc_attr__( 'Phone prefix', 'woo-vexpay-gateway' ) . '">';
		foreach ( VEXPay_Helpers::phone_prefixes() as $prefix ) {
			printf(
				'<option value="%1$s" %2$s>%1$s</option>',
				esc_attr( $prefix ),
				selected( $phone_prefix, $prefix, false )
			);
		}
		echo '</select>';
		printf(
			'<input type="tel" class="input-text vexpay-split-input" name="vexpay_debtor_phone_number" id="vexpay_debtor_phone_number" inputmode="numeric" autocomplete="tel-national" placeholder="1234567" maxlength="7" value="%s" aria-label="%s" />',
			esc_attr( preg_replace( '/\D+/', '', $phone_number ) ?? '' ),
			esc_attr__( 'Phone number', 'woo-vexpay-gateway' )
		);
		echo '</span></p>';

		echo '<p class="form-row form-row-wide vexpay-field-group vexpay-bank-field">';
		echo '<label id="vexpay_debtor_bank_label">' . esc_html__( 'Bank', 'woo-vexpay-gateway' ) . '&nbsp;<abbr class="required" title="required">*</abbr></label>';
		echo '<input type="hidden" name="vexpay_debtor_bank" id="vexpay_debtor_bank" value="' . esc_attr( $debtor_bank ) . '" />';
		echo '<div class="vexpay-bank-picker" data-vexpay-bank-picker>';
		echo '<button type="button" class="vexpay-bank-trigger" aria-haspopup="listbox" aria-expanded="false" aria-labelledby="vexpay_debtor_bank_label">';
		echo '<span class="vexpay-bank-trigger-content">';
		echo '<span class="vexpay-bank-placeholder">' . esc_html__( 'Select your bank', 'woo-vexpay-gateway' ) . '</span>';
		echo '</span>';
		echo '<span class="vexpay-bank-chevron" aria-hidden="true"></span>';
		echo '</button>';
		echo '<ul class="vexpay-bank-list" role="listbox" hidden>';
		foreach ( $banks as $bank ) {
			$selected = (string) $debtor_bank === (string) $bank['code'];
			printf(
				'<li role="option" class="vexpay-bank-option" data-code="%1$s" data-name="%2$s" data-logo="%3$s" aria-selected="%4$s" tabindex="-1">',
				esc_attr( $bank['code'] ),
				esc_attr( $bank['name'] ),
				esc_attr( $bank['logoUrl'] ? (string) $bank['logoUrl'] : '' ),
				$selected ? 'true' : 'false'
			);
			if ( ! empty( $bank['logoUrl'] ) ) {
				printf(
					'<img class="vexpay-bank-logo" src="%1$s" alt="" width="32" height="32" loading="lazy" decoding="async" />',
					esc_url( (string) $bank['logoUrl'] )
				);
			} else {
				echo '<span class="vexpay-bank-logo vexpay-bank-logo--fallback" aria-hidden="true">' . esc_html( substr( $bank['code'], -2 ) ) . '</span>';
			}
			echo '<span class="vexpay-bank-meta">';
			echo '<span class="vexpay-bank-name">' . esc_html( $bank['name'] ) . '</span> ';
			echo '<span class="vexpay-bank-code">(' . esc_html( $bank['code'] ) . ')</span>';
			echo '</span></li>';
		}
		echo '</ul></div></p>';

		echo '</fieldset>';
		echo '</div></div>'; // .vexpay-step-panel + .vexpay-flow
	}

	/**
	 * Fetch banks for checkout UI (code, name, logoUrl).
	 *
	 * @return array<int, array{code:string,name:string,logoUrl:?string}>
	 */
	public function fetch_banks_list(): array {
		$result = $this->get_api_client()->get_banks();
		if ( is_wp_error( $result ) ) {
			VEXPay_Logger::error( 'Banks fetch failed: ' . $result->get_error_message() );
			return array();
		}
		return VEXPay_Helpers::normalize_banks_list( $result );
	}

	/**
	 * Resolve a bank row from stored debtor bank code (SIMF or int).
	 *
	 * @param string $code Stored bank code.
	 * @return array{code:string,name:string,logoUrl:?string}|null
	 */
	private function find_bank_by_code( string $code ): ?array {
		$want = VEXPay_Helpers::normalize_bank_code( $code );
		if ( null === $want ) {
			return null;
		}

		foreach ( $this->fetch_banks_list() as $bank ) {
			if ( VEXPay_Helpers::normalize_bank_code( $bank['code'] ) === $want ) {
				return $bank;
			}
		}

		return null;
	}

	/**
	 * Validate checkout fields.
	 *
	 * @return bool
	 */
	public function validate_fields(): bool {
		$raw_id    = VEXPay_Helpers::resolve_debtor_id_from_request();
		$raw_phone = VEXPay_Helpers::resolve_phone_from_request();
		$raw_bank  = VEXPay_Helpers::get_request_field( 'vexpay_debtor_bank' );

		if ( ! VEXPay_Helpers::normalize_debtor_id( $raw_id ) ) {
			wc_add_notice( __( 'Enter a valid cédula/RIF (e.g. V12345678).', 'woo-vexpay-gateway' ), 'error' );
			return false;
		}
		if ( ! VEXPay_Helpers::normalize_phone( $raw_phone ) ) {
			wc_add_notice( __( 'Enter a valid Venezuelan mobile phone.', 'woo-vexpay-gateway' ), 'error' );
			return false;
		}
		if ( ! VEXPay_Helpers::normalize_bank_code( $raw_bank ) ) {
			wc_add_notice( __( 'Select your bank.', 'woo-vexpay-gateway' ), 'error' );
			return false;
		}

		return true;
	}

	/**
	 * Remembered payer details for checkout prefills.
	 *
	 * Priority: posted fields (classic refresh) → WC session → user meta → last VEXPay order.
	 *
	 * @return array{id_type:string,id_number:string,phone_prefix:string,phone_number:string,bank:string}
	 */
	public function get_debtor_profile(): array {
		$posted = $this->debtor_profile_from_request();
		if ( VEXPay_Helpers::debtor_profile_has_values( $posted ) ) {
			return $posted;
		}

		if ( function_exists( 'WC' ) && WC()->session ) {
			$session = WC()->session->get( VEXPay_Helpers::PROFILE_SESSION_KEY );
			if ( is_array( $session ) && VEXPay_Helpers::debtor_profile_has_values( $session ) ) {
				return VEXPay_Helpers::sanitize_debtor_profile( $session );
			}
		}

		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			$meta = get_user_meta( $user_id, VEXPay_Helpers::PROFILE_META_KEY, true );
			if ( is_array( $meta ) && VEXPay_Helpers::debtor_profile_has_values( $meta ) ) {
				return VEXPay_Helpers::sanitize_debtor_profile( $meta );
			}

			$from_order = $this->debtor_profile_from_last_order( $user_id );
			if ( VEXPay_Helpers::debtor_profile_has_values( $from_order ) ) {
				return $from_order;
			}
		}

		return VEXPay_Helpers::empty_debtor_profile();
	}

	/**
	 * Persist C2P payer details to WC session and (when logged in) user meta.
	 *
	 * @param string     $debtor_id Normalized debtor ID.
	 * @param string     $phone     Normalized 58… phone.
	 * @param string|int $bank      SIMF or numeric bank code.
	 */
	public function save_debtor_profile( string $debtor_id, string $phone, $bank ): void {
		$profile = VEXPay_Helpers::profile_from_normalized( $debtor_id, $phone, $bank );
		if ( ! VEXPay_Helpers::debtor_profile_has_values( $profile ) ) {
			return;
		}

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( VEXPay_Helpers::PROFILE_SESSION_KEY, $profile );
		}

		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, VEXPay_Helpers::PROFILE_META_KEY, $profile );
		}
	}

	/**
	 * Profile from classic checkout POST (or empty).
	 *
	 * @return array{id_type:string,id_number:string,phone_prefix:string,phone_number:string,bank:string}
	 */
	private function debtor_profile_from_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checkout fields; Woo verifies separately.
		if (
			! isset( $_POST['vexpay_debtor_id_type'] )
			&& ! isset( $_POST['vexpay_debtor_id_number'] )
			&& ! isset( $_POST['vexpay_debtor_phone_prefix'] )
			&& ! isset( $_POST['vexpay_debtor_phone_number'] )
			&& ! isset( $_POST['vexpay_debtor_bank'] )
			&& ! isset( $_POST['vexpay_debtor_id'] )
			&& ! isset( $_POST['vexpay_debtor_phone'] )
		) {
			return VEXPay_Helpers::empty_debtor_profile();
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$id    = VEXPay_Helpers::normalize_debtor_id( VEXPay_Helpers::resolve_debtor_id_from_request() );
		$phone = VEXPay_Helpers::normalize_phone( VEXPay_Helpers::resolve_phone_from_request() );
		$bank  = VEXPay_Helpers::get_request_field( 'vexpay_debtor_bank' );

		if ( $id && $phone ) {
			return VEXPay_Helpers::profile_from_normalized( $id, $phone, $bank );
		}

		return VEXPay_Helpers::sanitize_debtor_profile(
			array(
				'id_type'      => VEXPay_Helpers::get_request_field( 'vexpay_debtor_id_type' ) ?: 'V',
				'id_number'    => VEXPay_Helpers::get_request_field( 'vexpay_debtor_id_number' ),
				'phone_prefix' => VEXPay_Helpers::get_request_field( 'vexpay_debtor_phone_prefix' ) ?: '0412',
				'phone_number' => VEXPay_Helpers::get_request_field( 'vexpay_debtor_phone_number' ),
				'bank'         => $bank,
			)
		);
	}

	/**
	 * Build profile from the customer's most recent VEXPay order meta.
	 *
	 * @param int $user_id User ID.
	 * @return array{id_type:string,id_number:string,phone_prefix:string,phone_number:string,bank:string}
	 */
	private function debtor_profile_from_last_order( int $user_id ): array {
		$orders = wc_get_orders(
			array(
				'customer_id'  => $user_id,
				'payment_method' => 'vexpay',
				'limit'        => 1,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'return'       => 'objects',
			)
		);

		if ( empty( $orders ) || ! $orders[0] instanceof WC_Order ) {
			return VEXPay_Helpers::empty_debtor_profile();
		}

		$order = $orders[0];
		$id    = (string) $order->get_meta( VEXPay_Helpers::META_DEBTOR_ID );
		$phone = (string) $order->get_meta( VEXPay_Helpers::META_DEBTOR_PHONE );
		$bank  = (string) $order->get_meta( VEXPay_Helpers::META_DEBTOR_BANK );

		if ( '' === $id || '' === $phone ) {
			return VEXPay_Helpers::empty_debtor_profile();
		}

		return VEXPay_Helpers::profile_from_normalized( $id, $phone, $bank );
	}

	/**
	 * Create C2P intent and send customer to OTP step.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'woo-vexpay-gateway' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$debtor_id    = VEXPay_Helpers::normalize_debtor_id( VEXPay_Helpers::resolve_debtor_id_from_request() );
		$debtor_phone = VEXPay_Helpers::normalize_phone( VEXPay_Helpers::resolve_phone_from_request() );
		$debtor_bank  = VEXPay_Helpers::normalize_bank_code( VEXPay_Helpers::get_request_field( 'vexpay_debtor_bank' ) );

		if ( ! $debtor_id || ! $debtor_phone || ! $debtor_bank ) {
			wc_add_notice( __( 'Invalid C2P details.', 'woo-vexpay-gateway' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$usd          = VEXPay_Helpers::order_usd_amount( $order );
		$external_ref = VEXPay_Helpers::external_ref_for_order( (int) $order_id );

		$body = array(
			'usdAmount'       => $usd,
			'debtorId'        => $debtor_id,
			'debtorCellPhone' => $debtor_phone,
			'debtorBankCode'  => $debtor_bank,
			'externalRef'     => $external_ref,
		);

		$result = $this->get_api_client()->c2p_request( $body );
		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
			return array( 'result' => 'failure' );
		}

		$payment_id    = isset( $result['paymentId'] ) ? (string) $result['paymentId'] : '';
		$intent_id     = isset( $result['intentId'] ) ? (string) $result['intentId'] : $payment_id;
		$otp_requested = ! empty( $result['otpRequested'] );

		$order->update_meta_data( VEXPay_Helpers::META_PAYMENT_ID, $payment_id );
		$order->update_meta_data( VEXPay_Helpers::META_INTENT_ID, $intent_id );
		$order->update_meta_data( VEXPay_Helpers::META_EXTERNAL_REF, $external_ref );
		$order->update_meta_data( VEXPay_Helpers::META_DEBTOR_ID, $debtor_id );
		$order->update_meta_data( VEXPay_Helpers::META_DEBTOR_PHONE, $debtor_phone );
		$order->update_meta_data( VEXPay_Helpers::META_DEBTOR_BANK, (string) $debtor_bank );
		$order->update_meta_data( VEXPay_Helpers::META_USD_AMOUNT, (string) $usd );
		$this->store_fx_meta_from_result( $order, $result );
		$order->update_meta_data( VEXPay_Helpers::META_STATUS, isset( $result['status'] ) ? (string) $result['status'] : 'PENDING' );
		$order->update_meta_data( VEXPay_Helpers::META_OTP_REQUESTED, $otp_requested ? 'yes' : 'no' );
		$order->update_meta_data( VEXPay_Helpers::META_OTP_RESEND_AT, (string) time() );
		$order->update_status( 'on-hold', __( 'VEXPay C2P intent created. Waiting for OTP.', 'woo-vexpay-gateway' ) );
		$order->save();

		// Remember payer details for session + logged-in account (next checkout).
		$bank_simf = VEXPay_Helpers::get_request_field( 'vexpay_debtor_bank' );
		if ( '' === $bank_simf ) {
			$bank_simf = VEXPay_Helpers::format_simf_bank_code( $debtor_bank );
		}
		$this->save_debtor_profile( $debtor_id, $debtor_phone, $bank_simf );

		WC()->cart->empty_cart();

		// order-pay WITHOUT pay_for_order → order-receipt.php → woocommerce_receipt_vexpay (OTP step).
		return array(
			'result'   => 'success',
			'redirect' => $this->get_otp_redirect_url( $order ),
		);
	}

	/**
	 * Persist settlement FX fields from a VEXPay payment/intent receipt.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $result API result (PaymentReceiptDto).
	 */
	private function store_fx_meta_from_result( WC_Order $order, array $result ): void {
		if ( isset( $result['bcvRate'] ) && is_numeric( $result['bcvRate'] ) ) {
			$order->update_meta_data( VEXPay_Helpers::META_BCV_RATE, (string) $result['bcvRate'] );
		}
		if ( isset( $result['vesAmount'] ) && is_numeric( $result['vesAmount'] ) ) {
			$order->update_meta_data( VEXPay_Helpers::META_VES_AMOUNT, (string) $result['vesAmount'] );
		}
	}

	/**
	 * USD + BCV FX display values for an order (meta, with live quote fallback).
	 *
	 * @param WC_Order $order Order.
	 * @return array{usd:float,bcv_rate:float,ves_amount:float}
	 */
	private function get_order_fx_display( WC_Order $order ): array {
		$usd = (float) $order->get_meta( VEXPay_Helpers::META_USD_AMOUNT );
		if ( $usd <= 0 ) {
			$usd = VEXPay_Helpers::order_usd_amount( $order );
		}

		$bcv = (float) $order->get_meta( VEXPay_Helpers::META_BCV_RATE );
		$ves = (float) $order->get_meta( VEXPay_Helpers::META_VES_AMOUNT );

		if ( ( $bcv <= 0 || $ves <= 0 ) && $usd > 0 ) {
			$quote = $this->get_api_client()->get_quote( $usd );
			if ( ! is_wp_error( $quote ) ) {
				if ( $bcv <= 0 && isset( $quote['bcvRate'] ) && is_numeric( $quote['bcvRate'] ) ) {
					$bcv = (float) $quote['bcvRate'];
				}
				if ( $ves <= 0 && isset( $quote['vesAmount'] ) && is_numeric( $quote['vesAmount'] ) ) {
					$ves = (float) $quote['vesAmount'];
				}
			}
		}

		if ( $ves <= 0 && $usd > 0 && $bcv > 0 ) {
			$ves = round( $usd * $bcv, 2 );
		}

		return array(
			'usd'        => $usd,
			'bcv_rate'   => $bcv,
			'ves_amount' => $ves,
		);
	}

	/**
	 * URL for the C2P OTP step (dedicated page — Blocks order-pay skips receipt_page).
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public function get_otp_redirect_url( WC_Order $order ): string {
		return add_query_arg(
			array(
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
			),
			WC()->api_request_url( 'vexpay_otp_form' )
		);
	}

	/**
	 * Whether this order is waiting on the bank OTP step.
	 *
	 * @param WC_Order $order Order.
	 */
	public function order_awaits_otp( WC_Order $order ): bool {
		if ( $this->id !== $order->get_payment_method() || $order->is_paid() ) {
			return false;
		}
		$status = strtoupper( (string) $order->get_meta( VEXPay_Helpers::META_STATUS ) );
		return in_array( $status, array( '', 'PENDING' ), true );
	}

	/**
	 * Blocks/classic order-pay shows "Pay for order" instead of our OTP form — send them to step 2.
	 */
	public function maybe_redirect_order_pay_to_otp(): void {
		if ( ! is_wc_endpoint_url( 'order-pay' ) ) {
			return;
		}

		global $wp;
		$order_id = absint( $wp->query_vars['order-pay'] ?? 0 );
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! $this->order_awaits_otp( $order ) ) {
			return;
		}

		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return;
		}

		wp_safe_redirect( $this->get_otp_redirect_url( $order ) );
		exit;
	}

	/**
	 * Dedicated OTP form page (wc-api=vexpay_otp_form).
	 */
	public function render_otp_form_page(): void {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			wp_die( esc_html__( 'Invalid order.', 'woo-vexpay-gateway' ), 404 );
		}

		if ( $this->id !== $order->get_payment_method() ) {
			wp_die( esc_html__( 'This order is not a VEXPay payment.', 'woo-vexpay-gateway' ), 400 );
		}

		if ( $order->is_paid() || 'COMPLETED' === strtoupper( (string) $order->get_meta( VEXPay_Helpers::META_STATUS ) ) ) {
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		// Guests: key is enough. Logged-in users must own the order (or be shop manager).
		if ( is_user_logged_in() && (int) $order->get_user_id() > 0 && (int) $order->get_user_id() !== get_current_user_id() && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to pay for this order.', 'woo-vexpay-gateway' ), 403 );
		}

		status_header( 200 );
		nocache_headers();

		wp_enqueue_style(
			'vexpay-gateway',
			VEXPAY_GATEWAY_URL . 'assets/css/checkout.css',
			array(),
			VEXPAY_GATEWAY_VERSION
		);

		wp_enqueue_script(
			'vexpay-gateway',
			VEXPAY_GATEWAY_URL . 'assets/js/checkout.js',
			array( 'jquery' ),
			VEXPAY_GATEWAY_VERSION,
			true
		);

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$logo_url  = VEXPAY_GATEWAY_URL . 'assets/images/vexpay-logo.svg';
		$fx        = $this->get_order_fx_display( $order );
		$usd       = $fx['usd'];
		$bcv_rate  = $fx['bcv_rate'];
		$ves       = $fx['ves_amount'];
		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'Confirm payment — %s', 'woo-vexpay-gateway' ), $site_name ) ); ?></title>
	<link rel="preconnect" href="https://fonts.googleapis.com" />
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
	<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet" />
	<?php wp_head(); ?>
</head>
<body class="vexpay-otp-page">
	<div class="vexpay-otp-page__atmosphere" aria-hidden="true">
		<svg class="vexpay-otp-page__vectors" viewBox="0 0 1200 900" preserveAspectRatio="xMidYMid slice" focusable="false">
			<defs>
				<linearGradient id="vexRail" x1="0%" y1="0%" x2="100%" y2="100%">
					<stop offset="0%" stop-color="#613a8f" stop-opacity="0.55"/>
					<stop offset="100%" stop-color="#8b6bb5" stop-opacity="0.12"/>
				</linearGradient>
				<linearGradient id="vexMint" x1="0%" y1="100%" x2="100%" y2="0%">
					<stop offset="0%" stop-color="#1f8a5b" stop-opacity="0.28"/>
					<stop offset="100%" stop-color="#613a8f" stop-opacity="0.08"/>
				</linearGradient>
				<linearGradient id="vexOrb" x1="20%" y1="0%" x2="80%" y2="100%">
					<stop offset="0%" stop-color="#9b7bc4" stop-opacity="0.45"/>
					<stop offset="100%" stop-color="#613a8f" stop-opacity="0.05"/>
				</linearGradient>
			</defs>

			<!-- Soft mesh orbs -->
			<circle class="vex-vec vex-vec--orb-a" cx="140" cy="120" r="180" fill="url(#vexOrb)"/>
			<circle class="vex-vec vex-vec--orb-b" cx="1080" cy="160" r="150" fill="url(#vexMint)"/>
			<circle class="vex-vec vex-vec--orb-c" cx="980" cy="760" r="210" fill="url(#vexOrb)"/>

			<!-- Payment-rail arcs -->
			<path class="vex-vec vex-vec--rail" d="M-40 620 C 220 480, 420 780, 680 560 S 1040 420, 1240 640" fill="none" stroke="url(#vexRail)" stroke-width="1.5"/>
			<path class="vex-vec vex-vec--rail-soft" d="M-20 700 C 260 560, 480 820, 740 620 S 1100 500, 1260 720" fill="none" stroke="#613a8f" stroke-opacity="0.12" stroke-width="1"/>

			<!-- Floating card -->
			<g class="vex-vec vex-vec--card">
				<g transform="translate(70 520) rotate(-12)">
					<rect x="0" y="0" width="170" height="108" rx="14" fill="#fff" fill-opacity="0.55" stroke="#613a8f" stroke-opacity="0.18"/>
					<rect x="18" y="28" width="36" height="26" rx="5" fill="#613a8f" fill-opacity="0.22"/>
					<rect x="18" y="72" width="72" height="8" rx="4" fill="#613a8f" fill-opacity="0.14"/>
					<rect x="18" y="86" width="48" height="6" rx="3" fill="#613a8f" fill-opacity="0.1"/>
					<circle cx="138" cy="34" r="10" fill="#1f8a5b" fill-opacity="0.28"/>
					<circle cx="152" cy="34" r="10" fill="#613a8f" fill-opacity="0.22"/>
				</g>
			</g>

			<!-- Network nodes -->
			<g class="vex-vec vex-vec--net" fill="none" stroke="#613a8f" stroke-opacity="0.2" stroke-width="1.25">
				<path d="M860 240 L940 300 L1010 250 L1080 320"/>
				<circle cx="860" cy="240" r="5" fill="#613a8f" fill-opacity="0.35" stroke="none"/>
				<circle cx="940" cy="300" r="7" fill="#fff" stroke="#613a8f" stroke-opacity="0.35"/>
				<circle cx="1010" cy="250" r="5" fill="#1f8a5b" fill-opacity="0.4" stroke="none"/>
				<circle cx="1080" cy="320" r="6" fill="#613a8f" fill-opacity="0.28" stroke="none"/>
			</g>

			<!-- Shield / trust mark -->
			<g class="vex-vec vex-vec--shield">
				<g transform="translate(1040 560)">
					<path d="M40 8 L72 22 V48 C72 68 56 84 40 92 C24 84 8 68 8 48 V22 Z" fill="#fff" fill-opacity="0.4" stroke="#613a8f" stroke-opacity="0.22"/>
					<path d="M28 48 L36 56 L54 36" fill="none" stroke="#1f8a5b" stroke-opacity="0.65" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
				</g>
			</g>

			<!-- Abstract bars (ledger / sparkline) -->
			<g class="vex-vec vex-vec--bars">
				<g transform="translate(90 180)" fill="#613a8f">
					<rect x="0" y="42" width="10" height="28" rx="3" fill-opacity="0.16"/>
					<rect x="18" y="28" width="10" height="42" rx="3" fill-opacity="0.22"/>
					<rect x="36" y="14" width="10" height="56" rx="3" fill-opacity="0.28"/>
					<rect x="54" y="34" width="10" height="36" rx="3" fill-opacity="0.18"/>
					<rect x="72" y="8" width="10" height="62" rx="3" fill-opacity="0.32"/>
				</g>
			</g>

			<!-- Dotted orbit -->
			<circle class="vex-vec vex-vec--orbit" cx="600" cy="420" r="260" fill="none" stroke="#613a8f" stroke-opacity="0.1" stroke-width="1" stroke-dasharray="2 14"/>
		</svg>
	</div>
	<main class="vexpay-otp-page__main">
		<div class="vexpay-otp-page__card">
			<header class="vexpay-otp-page__header">
				<img
					class="vexpay-otp-page__logo"
					src="<?php echo esc_url( $logo_url ); ?>"
					alt="VEXPay"
					width="160"
					height="84"
					decoding="async"
				/>
			</header>

			<div class="vexpay-otp-page__summary">
				<div class="vexpay-otp-page__summary-row">
					<span class="vexpay-otp-page__order">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: order number */
								__( 'Order #%s', 'woo-vexpay-gateway' ),
								$order->get_order_number()
							)
						);
						?>
					</span>
					<?php if ( $usd > 0 ) : ?>
						<span class="vexpay-otp-page__amount">
							<?php echo esc_html( sprintf( '$%s USD', number_format_i18n( $usd, 2 ) ) ); ?>
						</span>
					<?php endif; ?>
				</div>
				<?php if ( $ves > 0 || $bcv_rate > 0 ) : ?>
					<div class="vexpay-otp-page__summary-row vexpay-otp-page__summary-row--fx">
						<?php if ( $ves > 0 ) : ?>
							<span class="vexpay-otp-page__ves">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: VES amount */
										__( 'Bs. %s', 'woo-vexpay-gateway' ),
										number_format_i18n( $ves, 2 )
									)
								);
								?>
							</span>
						<?php endif; ?>
						<?php if ( $bcv_rate > 0 ) : ?>
							<span class="vexpay-otp-page__rate">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: BCV FX rate */
										__( 'BCV %s', 'woo-vexpay-gateway' ),
										number_format_i18n( $bcv_rate, 4 )
									)
								);
								?>
							</span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>

			<?php
			if ( function_exists( 'wc_print_notices' ) ) {
				wc_print_notices();
			}
			$this->receipt_page( $order_id );
			?>

			<footer class="vexpay-otp-page__footer">
				<span class="vexpay-otp-page__secure" aria-hidden="true">
					<svg class="vexpay-otp-page__lock" viewBox="0 0 24 24" width="16" height="16" focusable="false">
						<defs>
							<linearGradient id="vexpayLockGrad" x1="0%" y1="0%" x2="100%" y2="100%">
								<stop offset="0%" stop-color="#7d54ab"/>
								<stop offset="55%" stop-color="#613a8f"/>
								<stop offset="100%" stop-color="#1f8a5b"/>
							</linearGradient>
						</defs>
						<path fill="url(#vexpayLockGrad)" d="M17 9V7a5 5 0 0 0-10 0v2H5.5A1.5 1.5 0 0 0 4 10.5v8A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5v-8A1.5 1.5 0 0 0 18.5 9H17Zm-8-2a3 3 0 0 1 6 0v2H9V7Zm3 10.25a1.75 1.75 0 1 1 0-3.5 1.75 1.75 0 0 1 0 3.5Z"/>
					</svg>
				</span>
				<?php echo esc_html__( 'Secured by VEXPay', 'woo-vexpay-gateway' ); ?>
			</footer>
		</div>
	</main>
	<?php wp_footer(); ?>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Shared step indicator markup.
	 *
	 * @param int $active Active step (1 or 2).
	 */
	private function render_checkout_steps( int $active ): void {
		$steps = array(
			1 => __( 'Your details', 'woo-vexpay-gateway' ),
			2 => __( 'OTP code', 'woo-vexpay-gateway' ),
		);
		echo '<ol class="vexpay-steps" aria-label="' . esc_attr__( 'Payment steps', 'woo-vexpay-gateway' ) . '">';
		foreach ( $steps as $num => $label ) {
			$classes = 'vexpay-step';
			if ( $num === $active ) {
				$classes .= ' is-active';
			} elseif ( $num < $active ) {
				$classes .= ' is-done';
			}
			printf(
				'<li class="%1$s"><span class="vexpay-step-num">%2$d</span><span class="vexpay-step-label">%3$s</span></li>',
				esc_attr( $classes ),
				(int) $num,
				esc_html( $label )
			);
		}
		echo '</ol>';
	}

	/**
	 * OTP form on order-pay / receipt (step 2).
	 *
	 * @param int $order_id Order ID.
	 */
	public function receipt_page( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$status = (string) $order->get_meta( VEXPay_Helpers::META_STATUS );
		if ( 'COMPLETED' === strtoupper( $status ) || $order->is_paid() ) {
			echo '<p>' . esc_html__( 'This order is already paid.', 'woo-vexpay-gateway' ) . '</p>';
			return;
		}

		$action        = WC()->api_request_url( 'vexpay_otp' );
		$bank          = (string) $order->get_meta( VEXPay_Helpers::META_DEBTOR_BANK );
		$phone         = (string) $order->get_meta( VEXPay_Helpers::META_DEBTOR_PHONE );
		$otp_requested = 'yes' === (string) $order->get_meta( VEXPay_Helpers::META_OTP_REQUESTED );
		$sandbox       = 'yes' === $this->get_option( 'testmode', 'yes' );
		$bank_info     = $this->find_bank_by_code( $bank );
		$bank_label    = $bank_info['name'] ?? VEXPay_Helpers::format_simf_bank_code( $bank );
		if ( '' === $bank_label && '' !== $bank ) {
			$bank_label = $bank;
		}
		$bank_code = $bank_info['code'] ?? VEXPay_Helpers::format_simf_bank_code( $bank );
		$bank_logo = ! empty( $bank_info['logoUrl'] ) ? (string) $bank_info['logoUrl'] : '';

		echo '<div class="vexpay-otp-wrap vexpay-flow">';
		$this->render_checkout_steps( 2 );

		if ( $sandbox ) {
			echo '<p class="vexpay-test-mode"><strong>' . esc_html__( 'SANDBOX', 'woo-vexpay-gateway' ) . '</strong> — ';
			echo esc_html__( "it's giving demo energy. No real money moves here — practice all you want.", 'woo-vexpay-gateway' );
			echo '</p>';
		}

		echo '<div class="vexpay-step-panel">';
		echo '<h1 class="vexpay-step-title vexpay-otp-title">' . esc_html__( 'Enter your OTP code', 'woo-vexpay-gateway' ) . '</h1>';

		if ( $otp_requested ) {
			echo '<p class="vexpay-step-copy">' . esc_html__( 'Your bank just texted a code — drop it in below and you’re good.', 'woo-vexpay-gateway' ) . '</p>';
		} elseif ( $sandbox ) {
			echo '<p class="vexpay-step-copy">' . esc_html__( 'No text this time — snag the sandbox code from the VEXPay Portal and paste it here.', 'woo-vexpay-gateway' ) . '</p>';
		} else {
			echo '<p class="vexpay-step-copy">' . esc_html__( 'Hop into your banking app, grab the one-time code, and type it here. (No SMS on this one.)', 'woo-vexpay-gateway' ) . '</p>';
		}

		if ( '' !== $bank_label || '' !== $phone ) {
			echo '<ul class="vexpay-otp-meta">';
			if ( '' !== $bank_label ) {
				echo '<li class="vexpay-otp-meta__bank">';
				echo '<span class="vexpay-otp-meta__label">' . esc_html__( 'Bank', 'woo-vexpay-gateway' ) . '</span>';
				echo '<span class="vexpay-otp-bank">';
				if ( '' !== $bank_logo ) {
					printf(
						'<img class="vexpay-otp-bank__logo" src="%1$s" alt="" width="32" height="32" loading="lazy" decoding="async" />',
						esc_url( $bank_logo )
					);
				} else {
					$fallback = '' !== $bank_code ? substr( $bank_code, -2 ) : '?';
					echo '<span class="vexpay-otp-bank__logo vexpay-otp-bank__logo--fallback" aria-hidden="true">' . esc_html( $fallback ) . '</span>';
				}
				echo '<span class="vexpay-otp-bank__name">' . esc_html( $bank_label ) . '</span>';
				echo '</span>';
				echo '</li>';
			}
			if ( '' !== $phone ) {
				$masked = strlen( $phone ) > 4 ? str_repeat( '•', max( 0, strlen( $phone ) - 4 ) ) . substr( $phone, -4 ) : $phone;
				echo '<li><span class="vexpay-otp-meta__label">' . esc_html__( 'Phone', 'woo-vexpay-gateway' ) . '</span> <span class="vexpay-otp-meta__value">' . esc_html( $masked ) . '</span></li>';
			}
			echo '</ul>';
		}

		echo '<form method="post" action="' . esc_url( $action ) . '" class="vexpay-otp-form">';
		wp_nonce_field( 'vexpay_otp_' . $order_id, 'vexpay_otp_nonce' );
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order_id ) . '" />';
		echo '<div class="form-row vexpay-field-group vexpay-otp-field">';
		echo '<label id="vexpay_token_label" for="vexpay_otp_cell_0">' . esc_html__( 'Your code', 'woo-vexpay-gateway' ) . '&nbsp;<span class="required">*</span></label>';
		echo '<input type="hidden" name="vexpay_token" id="vexpay_token" value="" autocomplete="one-time-code" />';
		echo '<div class="vexpay-otp-pin" data-vexpay-otp-pin role="group" aria-labelledby="vexpay_token_label">';
		for ( $i = 0; $i < 8; $i++ ) {
			printf(
				'<input type="text" class="vexpay-otp-cell" id="vexpay_otp_cell_%1$d" inputmode="numeric" maxlength="1" pattern="\\d*" autocomplete="%2$s" aria-label="%3$s" data-index="%1$d" />',
				(int) $i,
				0 === $i ? 'one-time-code' : 'off',
				/* translators: %d: digit position 1–8 */
				esc_attr( sprintf( __( 'Digit %d', 'woo-vexpay-gateway' ), $i + 1 ) )
			);
		}
		echo '</div>';
		echo '<p class="vexpay-otp-hint">' . esc_html__( '6–8 digits · paste works too', 'woo-vexpay-gateway' ) . '</p>';
		echo '</div>';
		echo '<p class="vexpay-otp-actions"><button type="submit" class="button alt vexpay-otp-submit">' . esc_html__( 'Confirm payment', 'woo-vexpay-gateway' ) . '</button></p>';
		echo '</form>';

		$resend_action = WC()->api_request_url( 'vexpay_otp_resend' );
		$cooldown_left = $this->otp_resend_cooldown_remaining( $order );
		echo '<form method="post" action="' . esc_url( $resend_action ) . '" class="vexpay-otp-resend-form">';
		wp_nonce_field( 'vexpay_otp_resend_' . $order_id, 'vexpay_otp_resend_nonce' );
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order_id ) . '" />';
		if ( $otp_requested ) {
			$resend_label = __( 'Didn’t get it? Resend code', 'woo-vexpay-gateway' );
		} else {
			$resend_label = __( 'Need a fresh code? Request again', 'woo-vexpay-gateway' );
		}
		printf(
			'<button type="submit" class="vexpay-otp-resend"%s>%s</button>',
			$cooldown_left > 0 ? ' disabled' : '',
			esc_html( $resend_label )
		);
		if ( $cooldown_left > 0 ) {
			echo '<p class="vexpay-otp-resend-wait">';
			echo esc_html(
				sprintf(
					/* translators: %d: seconds remaining */
					__( 'Hang tight — you can resend in %d sec.', 'woo-vexpay-gateway' ),
					$cooldown_left
				)
			);
			echo '</p>';
		} else {
			echo '<p class="vexpay-otp-resend-wait vexpay-otp-resend-wait--idle">';
			echo esc_html__( 'Wrong code? Edit and confirm again, or grab a fresh one above.', 'woo-vexpay-gateway' );
			echo '</p>';
		}
		echo '</form>';

		echo '</div></div>';
	}

	/**
	 * Hint on thank-you if still pending.
	 *
	 * @param int $order_id Order ID.
	 */
	public function thankyou_otp_notice( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->is_paid() ) {
			return;
		}
		$url = $this->get_otp_redirect_url( $order );
		echo '<p class="vexpay-thankyou-otp">';
		echo esc_html__( 'Payment is waiting for your bank OTP.', 'woo-vexpay-gateway' ) . ' ';
		echo '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Enter OTP code', 'woo-vexpay-gateway' ) . '</a>';
		echo '</p>';
	}

	/**
	 * Handle OTP form POST.
	 */
	public function handle_otp_submit(): void {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$nonce    = isset( $_POST['vexpay_otp_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['vexpay_otp_nonce'] ) ) : '';

		if ( ! $order_id || ! wp_verify_nonce( $nonce, 'vexpay_otp_' . $order_id ) ) {
			wp_die( esc_html__( 'Invalid OTP request.', 'woo-vexpay-gateway' ), 403 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			wp_die( esc_html__( 'Order not found.', 'woo-vexpay-gateway' ), 404 );
		}

		$token = isset( $_POST['vexpay_token'] ) ? sanitize_text_field( wp_unslash( $_POST['vexpay_token'] ) ) : '';
		if ( ! VEXPay_Helpers::is_valid_token( $token ) ) {
			wc_add_notice( __( 'OTP must be 6–8 digits.', 'woo-vexpay-gateway' ), 'error' );
			wp_safe_redirect( $this->get_otp_redirect_url( $order ) );
			exit;
		}

		$intent_id    = (string) $order->get_meta( VEXPay_Helpers::META_INTENT_ID );
		$debtor_id    = (string) $order->get_meta( VEXPay_Helpers::META_DEBTOR_ID );
		$debtor_phone = (string) $order->get_meta( VEXPay_Helpers::META_DEBTOR_PHONE );
		$debtor_bank  = (int) $order->get_meta( VEXPay_Helpers::META_DEBTOR_BANK );
		$usd          = (float) $order->get_meta( VEXPay_Helpers::META_USD_AMOUNT );
		$external_ref = (string) $order->get_meta( VEXPay_Helpers::META_EXTERNAL_REF );

		$body = array(
			'intentId'        => $intent_id,
			'usdAmount'       => $usd > 0 ? $usd : VEXPay_Helpers::order_usd_amount( $order ),
			'debtorId'        => $debtor_id,
			'debtorCellPhone' => $debtor_phone,
			'debtorBankCode'  => $debtor_bank,
			'token'           => $token,
			'externalRef'     => $external_ref,
		);

		$result = $this->get_api_client()->c2p_execute( $body );
		if ( is_wp_error( $result ) ) {
			$order->add_order_note( 'VEXPay C2P failed: ' . $result->get_error_message() );
			wc_add_notice( $result->get_error_message(), 'error' );
			wp_safe_redirect( $this->get_otp_redirect_url( $order ) );
			exit;
		}

		$this->apply_payment_result( $order, $result );

		if ( $order->is_paid() ) {
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		wc_add_notice( __( 'Payment was not completed. Check the OTP code and try again.', 'woo-vexpay-gateway' ), 'error' );
		wp_safe_redirect( $this->get_otp_redirect_url( $order ) );
		exit;
	}

	/**
	 * Seconds left before another OTP re-request is allowed.
	 *
	 * @param WC_Order $order Order.
	 * @return int
	 */
	private function otp_resend_cooldown_remaining( WC_Order $order ): int {
		$last = (int) $order->get_meta( VEXPay_Helpers::META_OTP_RESEND_AT );
		if ( $last <= 0 ) {
			return 0;
		}
		$elapsed = time() - $last;
		$left    = VEXPay_Helpers::OTP_RESEND_COOLDOWN - $elapsed;
		return $left > 0 ? $left : 0;
	}

	/**
	 * Re-request C2P intent / OTP for an awaiting order.
	 */
	public function handle_otp_resend(): void {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$nonce    = isset( $_POST['vexpay_otp_resend_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['vexpay_otp_resend_nonce'] ) ) : '';

		if ( ! $order_id || ! wp_verify_nonce( $nonce, 'vexpay_otp_resend_' . $order_id ) ) {
			wp_die( esc_html__( 'Invalid resend request.', 'woo-vexpay-gateway' ), 403 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			wp_die( esc_html__( 'Order not found.', 'woo-vexpay-gateway' ), 404 );
		}

		if ( ! $this->order_awaits_otp( $order ) ) {
			wc_add_notice( __( 'This order is not waiting for an OTP.', 'woo-vexpay-gateway' ), 'error' );
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		$cooldown = $this->otp_resend_cooldown_remaining( $order );
		if ( $cooldown > 0 ) {
			wc_add_notice(
				sprintf(
					/* translators: %d: seconds remaining */
					__( 'Easy — wait %d seconds before requesting another code.', 'woo-vexpay-gateway' ),
					$cooldown
				),
				'error'
			);
			wp_safe_redirect( $this->get_otp_redirect_url( $order ) );
			exit;
		}

		$debtor_id    = (string) $order->get_meta( VEXPay_Helpers::META_DEBTOR_ID );
		$debtor_phone = (string) $order->get_meta( VEXPay_Helpers::META_DEBTOR_PHONE );
		$debtor_bank  = VEXPay_Helpers::normalize_bank_code( $order->get_meta( VEXPay_Helpers::META_DEBTOR_BANK ) );
		$usd          = (float) $order->get_meta( VEXPay_Helpers::META_USD_AMOUNT );
		$external_ref = (string) $order->get_meta( VEXPay_Helpers::META_EXTERNAL_REF );

		if ( '' === $debtor_id || '' === $debtor_phone || null === $debtor_bank ) {
			wc_add_notice( __( 'Missing payment details — start checkout again.', 'woo-vexpay-gateway' ), 'error' );
			wp_safe_redirect( $this->get_otp_redirect_url( $order ) );
			exit;
		}

		$body = array(
			'usdAmount'       => $usd > 0 ? $usd : VEXPay_Helpers::order_usd_amount( $order ),
			'debtorId'        => $debtor_id,
			'debtorCellPhone' => $debtor_phone,
			'debtorBankCode'  => $debtor_bank,
			'externalRef'     => '' !== $external_ref ? $external_ref : VEXPay_Helpers::external_ref_for_order( (int) $order_id ),
		);

		$result = $this->get_api_client()->c2p_request( $body );
		if ( is_wp_error( $result ) ) {
			$order->add_order_note( 'VEXPay OTP resend failed: ' . $result->get_error_message() );
			wc_add_notice( $result->get_error_message(), 'error' );
			wp_safe_redirect( $this->get_otp_redirect_url( $order ) );
			exit;
		}

		$payment_id    = isset( $result['paymentId'] ) ? (string) $result['paymentId'] : '';
		$intent_id     = isset( $result['intentId'] ) ? (string) $result['intentId'] : $payment_id;
		$otp_requested = ! empty( $result['otpRequested'] );

		if ( '' !== $payment_id ) {
			$order->update_meta_data( VEXPay_Helpers::META_PAYMENT_ID, $payment_id );
		}
		if ( '' !== $intent_id ) {
			$order->update_meta_data( VEXPay_Helpers::META_INTENT_ID, $intent_id );
		}
		$this->store_fx_meta_from_result( $order, $result );
		$order->update_meta_data( VEXPay_Helpers::META_STATUS, isset( $result['status'] ) ? (string) $result['status'] : 'PENDING' );
		$order->update_meta_data( VEXPay_Helpers::META_OTP_REQUESTED, $otp_requested ? 'yes' : 'no' );
		$order->update_meta_data( VEXPay_Helpers::META_OTP_RESEND_AT, (string) time() );
		$order->add_order_note( __( 'Customer requested a new VEXPay OTP / C2P intent.', 'woo-vexpay-gateway' ) );
		$order->save();

		if ( $otp_requested ) {
			wc_add_notice( __( 'Fresh code incoming — check your texts and drop it in.', 'woo-vexpay-gateway' ), 'success' );
		} else {
			wc_add_notice( __( 'Ready for round two — grab a fresh code from your bank app / VEXPay Portal.', 'woo-vexpay-gateway' ), 'success' );
		}

		wp_safe_redirect( $this->get_otp_redirect_url( $order ) );
		exit;
	}

	/**
	 * Apply API receipt to order.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $result Receipt.
	 */
	public function apply_payment_result( WC_Order $order, array $result ): void {
		$status = isset( $result['status'] ) ? strtoupper( (string) $result['status'] ) : '';
		if ( ! empty( $result['paymentId'] ) ) {
			$order->update_meta_data( VEXPay_Helpers::META_PAYMENT_ID, (string) $result['paymentId'] );
		}
		$this->store_fx_meta_from_result( $order, $result );
		$order->update_meta_data( VEXPay_Helpers::META_STATUS, $status );

		$action = VEXPay_Helpers::map_payment_status( $status );

		if ( 'complete' === $action ) {
			$txn = ! empty( $result['bankReference'] ) ? (string) $result['bankReference'] : (string) ( $result['paymentId'] ?? '' );
			if ( ! $order->is_paid() ) {
				$order->payment_complete( $txn );
				$order->add_order_note(
					sprintf(
						/* translators: %s: payment id */
						__( 'VEXPay C2P completed (%s).', 'woo-vexpay-gateway' ),
						(string) ( $result['paymentId'] ?? '' )
					)
				);
			}
		} elseif ( 'fail' === $action ) {
			$reason = ! empty( $result['failureCode'] ) ? (string) $result['failureCode'] : $status;
			$order->update_status( 'failed', 'VEXPay: ' . $reason );
		} elseif ( 'refund' === $action ) {
			if ( ! $order->has_status( 'refunded' ) ) {
				$order->update_status( 'refunded', __( 'VEXPay payment reversed.', 'woo-vexpay-gateway' ) );
			}
		} elseif ( 'hold' === $action && ! $order->has_status( array( 'on-hold', 'pending' ) ) ) {
			$order->update_status( 'on-hold', __( 'VEXPay payment pending.', 'woo-vexpay-gateway' ) );
		}

		$order->save();
	}

	/**
	 * Process refund via C2P reverse (full only).
	 *
	 * @param int        $order_id Order ID.
	 * @param float|null $amount   Amount.
	 * @param string     $reason   Reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'vexpay_refund', __( 'Order not found.', 'woo-vexpay-gateway' ) );
		}

		$payment_id = (string) $order->get_meta( VEXPay_Helpers::META_PAYMENT_ID );
		if ( '' === $payment_id ) {
			return new WP_Error( 'vexpay_refund', __( 'Missing VEXPay payment ID.', 'woo-vexpay-gateway' ) );
		}

		$order_total = (float) $order->get_total();
		if ( null !== $amount && abs( (float) $amount - $order_total ) > 0.009 ) {
			return new WP_Error(
				'vexpay_refund',
				__( 'Partial refunds are not supported. Refund the full order amount to reverse the C2P payment.', 'woo-vexpay-gateway' )
			);
		}

		$result = $this->get_api_client()->reverse_payment( $payment_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$order->update_meta_data( VEXPay_Helpers::META_STATUS, 'REVERSED' );
		$order->add_order_note(
			sprintf(
				/* translators: 1: payment id 2: reason */
				__( 'VEXPay reverse requested for %1$s. %2$s', 'woo-vexpay-gateway' ),
				$payment_id,
				$reason
			)
		);
		$order->save();

		return true;
	}
}
