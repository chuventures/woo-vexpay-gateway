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

require_once dirname( __DIR__ ) . '/includes/class-vexpay-helpers.php';
