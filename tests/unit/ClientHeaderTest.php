<?php
/**
 * VexPay-Client header unit tests.
 *
 * @package VEXPay_Gateway
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The plugin identifies itself to VEX Pay so plugin usage can be measured.
 */
final class ClientHeaderTest extends TestCase {

	public function test_client_header_names_the_plugin_and_php(): void {
		$header = json_decode( VEXPay_API_Client::client_header(), true );
		$this->assertSame( 'vexpay-woocommerce', $header['sdk'] );
		$this->assertSame( VEXPAY_GATEWAY_VERSION, $header['sdkVersion'] );
		$this->assertSame( 'php', $header['runtime'] );
		$this->assertSame( PHP_VERSION, $header['runtimeVersion'] );
		$this->assertLessThanOrEqual( 512, strlen( VEXPay_API_Client::client_header() ) );
	}
}
