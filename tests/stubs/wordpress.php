<?php
/**
 * Minimal WordPress / WooCommerce fakes for unit tests (no WordPress bootstrap).
 *
 * State lives in $GLOBALS['vexpay_test'] and is reset by vexpay_test_reset().
 *
 * @package VEXPay_Gateway
 */

// phpcs:ignoreFile

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'VEXPAY_GATEWAY_URL' ) ) {
	define( 'VEXPAY_GATEWAY_URL', 'https://shop.test/wp-content/plugins/woo-vexpay-gateway/' );
}
if ( ! defined( 'VEXPAY_GATEWAY_PATH' ) ) {
	define( 'VEXPAY_GATEWAY_PATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'VEXPAY_GATEWAY_API_BASE_URL' ) ) {
	define( 'VEXPAY_GATEWAY_API_BASE_URL', 'https://api.vexpay.test' );
}

/**
 * Reset fake WordPress state.
 */
function vexpay_test_reset(): void {
	$GLOBALS['vexpay_test'] = array(
		'options'    => array(),
		'transients' => array(),
		'post_meta'  => array(),
		'filters'    => array(),
		'notices'    => array(),
		'logs'       => array(),
		'orders'     => array(),
		'cart'       => array(),
		'cart_empty' => 0,
	);
}
vexpay_test_reset();

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

function add_action( ...$args ): bool {
	return true;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ): bool {
	$GLOBALS['vexpay_test']['filters'][ $hook ][] = array( $callback, $accepted_args );
	return true;
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['vexpay_test']['filters'][ $hook ] ?? array() as $entry ) {
		list( $callback, $accepted ) = $entry;
		$value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, max( 1, (int) $accepted ) ) );
	}
	return $value;
}

function get_option( $name, $default = false ) {
	return $GLOBALS['vexpay_test']['options'][ $name ] ?? $default;
}

function update_option( $name, $value ): bool {
	$GLOBALS['vexpay_test']['options'][ $name ] = $value;
	return true;
}

function get_transient( $name ) {
	return $GLOBALS['vexpay_test']['transients'][ $name ] ?? false;
}

function set_transient( $name, $value, $ttl = 0 ): bool {
	$GLOBALS['vexpay_test']['transients'][ $name ] = $value;
	return true;
}

function delete_transient( $name ): bool {
	unset( $GLOBALS['vexpay_test']['transients'][ $name ] );
	return true;
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	return $GLOBALS['vexpay_test']['post_meta'][ (int) $post_id ][ $key ] ?? '';
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( $text, $domain = 'default' ) {
	return esc_html( $text );
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, (int) $decimals );
}

function is_admin(): bool {
	return false;
}

function wc_add_notice( $message, $type = 'success' ): void {
	$GLOBALS['vexpay_test']['notices'][] = array( $type, $message );
}

function wc_get_order( $id ) {
	return $GLOBALS['vexpay_test']['orders'][ (int) $id ] ?? false;
}

function wc_get_checkout_url() {
	return 'https://shop.test/checkout/';
}

function map_deep( $value, $callback ) {
	return is_array( $value ) ? array_map( fn( $v ) => map_deep( $v, $callback ), $value ) : $callback( $value );
}

class VEXPay_Test_Logger {
	public function log( $level, $message, $context = array() ): void {
		$GLOBALS['vexpay_test']['logs'][] = array( $level, $message );
	}
}

function wc_get_logger() {
	return new VEXPay_Test_Logger();
}

class VEXPay_Test_Cart {
	public function get_cart(): array {
		return $GLOBALS['vexpay_test']['cart'];
	}

	public function empty_cart(): void {
		++$GLOBALS['vexpay_test']['cart_empty'];
	}
}

class VEXPay_Test_Session {
	public $has_session = false;
	public $cookie_set  = false;

	public function has_session() {
		return $this->has_session;
	}

	public function set_customer_session_cookie( $set ) {
		$this->cookie_set  = (bool) $set;
		$this->has_session = true;
	}
}

class VEXPay_Test_WC {
	public $cart;
	public $session;

	public function __construct() {
		$this->cart    = new VEXPay_Test_Cart();
		$this->session = new VEXPay_Test_Session();
	}
}

function WC() {
	static $wc = null;
	if ( null === $wc ) {
		$wc = new VEXPay_Test_WC();
	}
	return $wc;
}

class WC_Product {
	private $id;

	public function __construct( int $id = 0 ) {
		$this->id = $id;
	}

	public function get_id(): int {
		return $this->id;
	}
}

class WC_Order_Item_Product {
	private $data;

	public function __construct( array $data ) {
		$this->data = array_merge(
			array(
				'product_id'   => 0,
				'variation_id' => 0,
				'total'        => 0,
				'tax'          => 0,
				'name'         => 'Item',
			),
			$data
		);
	}

	public function get_product_id() {
		return $this->data['product_id'];
	}

	public function get_variation_id() {
		return $this->data['variation_id'];
	}

	public function get_total() {
		return $this->data['total'];
	}

	public function get_total_tax() {
		return $this->data['tax'];
	}

	public function get_name() {
		return $this->data['name'];
	}
}

class WC_Order {
	public $id;
	public $meta     = array();
	public $items    = array();
	public $total    = 0.0;
	public $status   = 'pending';
	public $notes    = array();
	public $paid     = false;
	public $saves    = 0;
	public $txn      = '';

	public function __construct( int $id = 1, array $items = array(), float $total = 0.0 ) {
		$this->id    = $id;
		$this->items = $items;
		$this->total = $total;
		$GLOBALS['vexpay_test']['orders'][ $id ] = $this;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_order_number() {
		return (string) $this->id;
	}

	public function get_items( $type = 'line_item' ) {
		return 'line_item' === $type ? $this->items : array();
	}

	public function get_total() {
		return $this->total;
	}

	public function get_meta( $key, $single = true ) {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( $key, $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( $key ): void {
		unset( $this->meta[ $key ] );
	}

	public function save() {
		++$this->saves;
		return $this->id;
	}

	public function update_status( $status, $note = '' ): void {
		$this->status = $status;
		if ( '' !== $note ) {
			$this->notes[] = $note;
		}
	}

	public function add_order_note( $note ): void {
		$this->notes[] = $note;
	}

	public function has_status( $status ): bool {
		return in_array( $this->status, (array) $status, true );
	}

	public function is_paid(): bool {
		return $this->paid;
	}

	public function payment_complete( $txn = '' ): void {
		$this->paid   = true;
		$this->status = 'processing';
		$this->txn    = $txn;
	}

	public function set_transaction_id( $txn ): void {
		$this->txn = $txn;
	}

	public function get_formatted_billing_full_name() {
		return 'Ana Perez';
	}

	public function get_billing_first_name() {
		return 'Ana';
	}

	public function get_billing_last_name() {
		return 'Perez';
	}

	public function get_payment_method() {
		return $this->meta['_payment_method'] ?? '';
	}
}

/**
 * Bare WC_Payment_Gateway: settings array + option reader.
 */
class WC_Payment_Gateway {
	public $id = '';
	public $settings = array();
	public $form_fields = array();
	public $enabled = 'yes';
	public $title = '';
	public $description = '';
	public $method_title = '';
	public $method_description = '';
	public $has_fields = false;
	public $supports = array();
	public $icon = '';

	public function get_option( $key, $empty_value = null ) {
		return $this->settings[ $key ] ?? $empty_value;
	}

	public function update_option( $key, $value = '' ): bool {
		$this->settings[ $key ] = $value;
		return true;
	}

	public function init_settings(): void {
	}

	public function is_available() {
		return 'yes' === $this->enabled;
	}

	public function get_return_url( $order = null ) {
		return 'https://shop.test/checkout/order-received/';
	}
}
