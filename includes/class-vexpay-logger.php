<?php
/**
 * WooCommerce logger wrapper.
 *
 * @package VEXPay_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Logger.
 */
class VEXPay_Logger {

	/**
	 * Source channel.
	 *
	 * @var string
	 */
	private const SOURCE = 'vexpay';

	/**
	 * Whether logging is enabled for the gateway.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		$settings = get_option( 'woocommerce_vexpay_settings', array() );
		return isset( $settings['debug'] ) && 'yes' === $settings['debug'];
	}

	/**
	 * Log a message.
	 *
	 * @param string $level   Level.
	 * @param string $message Message (never include API keys).
	 */
	public static function log( string $level, string $message ): void {
		if ( ! self::enabled() || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->log( $level, $message, array( 'source' => self::SOURCE ) );
	}

	/**
	 * Info.
	 *
	 * @param string $message Message.
	 */
	public static function info( string $message ): void {
		self::log( 'info', $message );
	}

	/**
	 * Error.
	 *
	 * @param string $message Message.
	 */
	public static function error( string $message ): void {
		self::log( 'error', $message );
	}

	/**
	 * Error that is logged even when the Debug log setting is off (e.g. a refused
	 * marketplace split). Never include API keys or card data.
	 *
	 * @param string $message Message.
	 */
	public static function always_error( string $message ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->log( 'error', $message, array( 'source' => self::SOURCE ) );
	}

	/**
	 * Debug.
	 *
	 * @param string $message Message.
	 */
	public static function debug( string $message ): void {
		self::log( 'debug', $message );
	}
}
