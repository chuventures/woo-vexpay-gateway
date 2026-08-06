<?php
/**
 * WooCommerce payment gateway — VEXPay C2P.
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
		$this->method_description = __( 'Accept Venezuela C2P payments via VEXPay (bank OTP).', 'woo-vexpay-gateway' );
		$this->has_fields         = true;
		$this->supports           = array( 'products', 'refunds' );
		$this->icon               = VEXPAY_GATEWAY_URL . 'assets/images/vexpay-logo.svg';

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'C2P / Pago móvil (VEXPay)', 'woo-vexpay-gateway' ) );
		$this->description = $this->get_option( 'description', __( 'Pay with your bank C2P token (OTP).', 'woo-vexpay-gateway' ) );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'maybe_register_webhook' ) );

		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_api_vexpay_otp', array( $this, 'handle_otp_submit' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_otp_notice' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
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
				'description' => __( 'HMAC secret for X-Webhook-Signature. Filled automatically when the plugin registers a webhook, or paste from the VEXPay dashboard.', 'woo-vexpay-gateway' ),
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

		$client   = $this->get_api_client();
		$url      = $this->get_webhook_url();
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
		if ( $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}

		if ( 'yes' === $this->get_option( 'testmode', 'yes' ) ) {
			echo '<p class="vexpay-test-mode"><strong>' . esc_html__( 'SANDBOX', 'woo-vexpay-gateway' ) . '</strong> — ';
			echo esc_html__( 'Use your VEXPay sandbox OTP.', 'woo-vexpay-gateway' ) . '</p>';
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

		$debtor_id_type   = isset( $_POST['vexpay_debtor_id_type'] ) ? sanitize_text_field( wp_unslash( $_POST['vexpay_debtor_id_type'] ) ) : 'V'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$debtor_id_number = isset( $_POST['vexpay_debtor_id_number'] ) ? sanitize_text_field( wp_unslash( $_POST['vexpay_debtor_id_number'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$phone_prefix     = isset( $_POST['vexpay_debtor_phone_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['vexpay_debtor_phone_prefix'] ) ) : '0412'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$phone_number     = isset( $_POST['vexpay_debtor_phone_number'] ) ? sanitize_text_field( wp_unslash( $_POST['vexpay_debtor_phone_number'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$debtor_bank      = isset( $_POST['vexpay_debtor_bank'] ) ? sanitize_text_field( wp_unslash( $_POST['vexpay_debtor_bank'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! in_array( strtoupper( $debtor_id_type ), VEXPay_Helpers::debtor_id_types(), true ) ) {
			$debtor_id_type = 'V';
		}
		if ( ! in_array( $phone_prefix, VEXPay_Helpers::phone_prefixes(), true ) ) {
			$phone_prefix = '0412';
		}

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
			echo '<span class="vexpay-bank-code">' . esc_html( $bank['code'] ) . '</span>';
			echo '<span class="vexpay-bank-name">' . esc_html( $bank['name'] ) . '</span>';
			echo '</span></li>';
		}
		echo '</ul></div></p>';

		echo '</fieldset>';
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
	 * Bank select options (classic fallback labels).
	 *
	 * @return array
	 */
	private function fetch_banks_options(): array {
		$options = array( '' => __( 'Select your bank', 'woo-vexpay-gateway' ) );
		foreach ( $this->fetch_banks_list() as $bank ) {
			$options[ $bank['code'] ] = $bank['code'] . ' — ' . $bank['name'];
		}
		return $options;
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

		$payment_id = isset( $result['paymentId'] ) ? (string) $result['paymentId'] : '';
		$intent_id  = isset( $result['intentId'] ) ? (string) $result['intentId'] : $payment_id;

		$order->update_meta_data( VEXPay_Helpers::META_PAYMENT_ID, $payment_id );
		$order->update_meta_data( VEXPay_Helpers::META_INTENT_ID, $intent_id );
		$order->update_meta_data( VEXPay_Helpers::META_EXTERNAL_REF, $external_ref );
		$order->update_meta_data( VEXPay_Helpers::META_DEBTOR_ID, $debtor_id );
		$order->update_meta_data( VEXPay_Helpers::META_DEBTOR_PHONE, $debtor_phone );
		$order->update_meta_data( VEXPay_Helpers::META_DEBTOR_BANK, (string) $debtor_bank );
		$order->update_meta_data( VEXPay_Helpers::META_USD_AMOUNT, (string) $usd );
		$order->update_meta_data( VEXPay_Helpers::META_STATUS, isset( $result['status'] ) ? (string) $result['status'] : 'PENDING' );
		$order->update_status( 'on-hold', __( 'VEXPay C2P intent created. Waiting for OTP.', 'woo-vexpay-gateway' ) );
		$order->save();

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * OTP form on order-pay / receipt.
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

		$action = WC()->api_request_url( 'vexpay_otp' );

		echo '<div class="vexpay-otp-wrap">';
		echo '<p>' . esc_html__( 'Open your banking app and generate the C2P token / OTP, then enter it below.', 'woo-vexpay-gateway' ) . '</p>';
		echo '<form method="post" action="' . esc_url( $action ) . '" class="vexpay-otp-form">';
		wp_nonce_field( 'vexpay_otp_' . $order_id, 'vexpay_otp_nonce' );
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order_id ) . '" />';
		echo '<p class="form-row">';
		echo '<label for="vexpay_token">' . esc_html__( 'OTP / token', 'woo-vexpay-gateway' ) . '&nbsp;<span class="required">*</span></label>';
		echo '<input type="text" class="input-text" name="vexpay_token" id="vexpay_token" inputmode="numeric" pattern="\\d{6,8}" maxlength="8" required autocomplete="one-time-code" />';
		echo '</p>';
		echo '<p><button type="submit" class="button alt">' . esc_html__( 'Confirm payment', 'woo-vexpay-gateway' ) . '</button></p>';
		echo '</form>';
		echo '</div>';
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
		$url = $order->get_checkout_payment_url( true );
		echo '<p class="vexpay-thankyou-otp">';
		echo esc_html__( 'Payment is waiting for your bank OTP.', 'woo-vexpay-gateway' ) . ' ';
		echo '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Enter OTP', 'woo-vexpay-gateway' ) . '</a>';
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
			wp_safe_redirect( $order->get_checkout_payment_url( true ) );
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
			wp_safe_redirect( $order->get_checkout_payment_url( true ) );
			exit;
		}

		$this->apply_payment_result( $order, $result );

		if ( $order->is_paid() ) {
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		wc_add_notice( __( 'Payment was not completed. Check the OTP and try again.', 'woo-vexpay-gateway' ), 'error' );
		wp_safe_redirect( $order->get_checkout_payment_url( true ) );
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
