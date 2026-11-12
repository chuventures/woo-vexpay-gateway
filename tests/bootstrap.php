<?php
/**
 * PHPUnit bootstrap (no WordPress).
 *
 * @package VEXPay_Gateway
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'VEXPAY_GATEWAY_VERSION', '1.0.0' );

/**
 * Minimal sanitize stub for helpers that call sanitize_text_field in get_request_field.
 * Unit tests for signature/normalize do not need it; define for safety.
 */
if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * @param string $str Input.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		return is_string( $str ) ? trim( strip_tags( $str ) ) : '';
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * @param mixed $value Value.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return $text;
	}
}

require_once __DIR__ . '/stubs/wordpress.php';

require_once dirname( __DIR__ ) . '/includes/class-vexpay-helpers.php';
require_once dirname( __DIR__ ) . '/includes/class-vexpay-logger.php';
require_once dirname( __DIR__ ) . '/includes/class-vexpay-api-client.php';
require_once dirname( __DIR__ ) . '/includes/class-vexpay-marketplace.php';
require_once dirname( __DIR__ ) . '/includes/class-vexpay-marketplace-admin.php';
require_once dirname( __DIR__ ) . '/includes/vexpay-functions.php';
require_once dirname( __DIR__ ) . '/includes/class-vexpay-gateway.php';
require_once dirname( __DIR__ ) . '/includes/class-vexpay-gateway-vpos.php';
require_once dirname( __DIR__ ) . '/includes/class-vexpay-gateway-usdt.php';
