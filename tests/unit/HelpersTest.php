<?php
/**
 * Helper unit tests.
 *
 * @package VEXPay_Gateway
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for VEXPay_Helpers.
 */
final class HelpersTest extends TestCase {

	public function test_normalize_debtor_id(): void {
		$this->assertSame( 'V12345678', VEXPay_Helpers::normalize_debtor_id( 'v12345678' ) );
		$this->assertSame( 'V12345678', VEXPay_Helpers::normalize_debtor_id( 'V-12.345.678' ) );
		$this->assertNull( VEXPay_Helpers::normalize_debtor_id( '12345678' ) );
		$this->assertNull( VEXPay_Helpers::normalize_debtor_id( 'X123' ) );
	}

	public function test_normalize_phone(): void {
		$this->assertSame( '584121234567', VEXPay_Helpers::normalize_phone( '04121234567' ) );
		$this->assertSame( '584121234567', VEXPay_Helpers::normalize_phone( '4121234567' ) );
		$this->assertSame( '584121234567', VEXPay_Helpers::normalize_phone( '+58 412-1234567' ) );
		$this->assertNull( VEXPay_Helpers::normalize_phone( '123' ) );
	}

	public function test_compose_debtor_id(): void {
		$this->assertSame( 'V12345678', VEXPay_Helpers::compose_debtor_id( 'V', '12345678' ) );
		$this->assertSame( 'J123456789', VEXPay_Helpers::compose_debtor_id( 'j', '123456789' ) );
		$this->assertSame( 'E1234567', VEXPay_Helpers::compose_debtor_id( 'E', '1.234.567' ) );
		$this->assertNull( VEXPay_Helpers::compose_debtor_id( 'V', '12' ) );
		$this->assertNull( VEXPay_Helpers::compose_debtor_id( 'X', '12345678' ) );
	}

	public function test_compose_phone(): void {
		$this->assertSame( '584121234567', VEXPay_Helpers::compose_phone( '0412', '1234567' ) );
		$this->assertSame( '584241234567', VEXPay_Helpers::compose_phone( '0424', '1234567' ) );
		$this->assertNull( VEXPay_Helpers::compose_phone( '0412', '123456' ) );
		$this->assertNull( VEXPay_Helpers::compose_phone( '0412', '12345678' ) );
		$this->assertNull( VEXPay_Helpers::compose_phone( '0400', '1234567' ) );
	}

	public function test_normalize_banks_list(): void {
		$banks = VEXPay_Helpers::normalize_banks_list(
			array(
				array(
					'code'    => '0102',
					'name'    => 'Banco de Venezuela',
					'logoUrl' => 'https://cdn.example.com/0102.png',
				),
				array(
					'code'    => '0104',
					'name'    => 'BVC',
					'logoUrl' => null,
				),
				array( 'name' => 'missing-code' ),
			)
		);

		$this->assertCount( 2, $banks );
		$this->assertSame( '0102', $banks[0]['code'] );
		$this->assertSame( 'https://cdn.example.com/0102.png', $banks[0]['logoUrl'] );
		$this->assertSame( '0104', $banks[1]['code'] );
		$this->assertNull( $banks[1]['logoUrl'] );
	}

	public function test_normalize_bank_code(): void {
		$this->assertSame( 102, VEXPay_Helpers::normalize_bank_code( '0102' ) );
		$this->assertSame( 102, VEXPay_Helpers::normalize_bank_code( 102 ) );
		$this->assertNull( VEXPay_Helpers::normalize_bank_code( '' ) );
	}

	public function test_format_simf_bank_code(): void {
		$this->assertSame( '0102', VEXPay_Helpers::format_simf_bank_code( 102 ) );
		$this->assertSame( '0102', VEXPay_Helpers::format_simf_bank_code( '0102' ) );
		$this->assertSame( '', VEXPay_Helpers::format_simf_bank_code( '' ) );
	}

	public function test_split_debtor_id(): void {
		$this->assertSame(
			array( 'type' => 'V', 'number' => '12345678' ),
			VEXPay_Helpers::split_debtor_id( 'V12345678' )
		);
		$this->assertSame(
			array( 'type' => 'J', 'number' => '123456789' ),
			VEXPay_Helpers::split_debtor_id( 'j123456789' )
		);
		// P is API-valid but not in checkout selector → map type to V, keep digits.
		$this->assertSame(
			array( 'type' => 'V', 'number' => '12345678' ),
			VEXPay_Helpers::split_debtor_id( 'P12345678' )
		);
	}

	public function test_split_phone(): void {
		$this->assertSame(
			array( 'prefix' => '0412', 'number' => '1234567' ),
			VEXPay_Helpers::split_phone( '584121234567' )
		);
		$this->assertSame(
			array( 'prefix' => '0414', 'number' => '9333844' ),
			VEXPay_Helpers::split_phone( '04149333844' )
		);
	}

	public function test_profile_from_normalized(): void {
		$profile = VEXPay_Helpers::profile_from_normalized( 'V12345678', '584121234567', '0102' );
		$this->assertSame( 'V', $profile['id_type'] );
		$this->assertSame( '12345678', $profile['id_number'] );
		$this->assertSame( '0412', $profile['phone_prefix'] );
		$this->assertSame( '1234567', $profile['phone_number'] );
		$this->assertSame( '0102', $profile['bank'] );
		$this->assertTrue( VEXPay_Helpers::debtor_profile_has_values( $profile ) );
		$this->assertFalse( VEXPay_Helpers::debtor_profile_has_values( VEXPay_Helpers::empty_debtor_profile() ) );
	}

	public function test_is_valid_token(): void {
		$this->assertTrue( VEXPay_Helpers::is_valid_token( '123456' ) );
		$this->assertTrue( VEXPay_Helpers::is_valid_token( '12345678' ) );
		$this->assertFalse( VEXPay_Helpers::is_valid_token( '12345' ) );
		$this->assertFalse( VEXPay_Helpers::is_valid_token( 'abcdef' ) );
	}

	public function test_webhook_signature(): void {
		$secret = 'test-secret';
		$body   = '{"event":"payment.completed","data":{"paymentId":"abc"},"timestamp":"2026-01-01T00:00:00.000Z"}';
		$sig    = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

		$this->assertTrue( VEXPay_Helpers::verify_webhook_signature( $body, $secret, $sig ) );
		$this->assertFalse( VEXPay_Helpers::verify_webhook_signature( $body, $secret, 'sha256=deadbeef' ) );
		$this->assertFalse( VEXPay_Helpers::verify_webhook_signature( $body, '', $sig ) );
	}

	public function test_map_payment_status(): void {
		$this->assertSame( 'complete', VEXPay_Helpers::map_payment_status( 'COMPLETED' ) );
		$this->assertSame( 'fail', VEXPay_Helpers::map_payment_status( 'FAILED' ) );
		$this->assertSame( 'fail', VEXPay_Helpers::map_payment_status( 'CANCELED' ) );
		$this->assertSame( 'refund', VEXPay_Helpers::map_payment_status( 'REVERSED' ) );
		$this->assertSame( 'hold', VEXPay_Helpers::map_payment_status( 'PENDING' ) );
		$this->assertSame( 'ignore', VEXPay_Helpers::map_payment_status( 'UNKNOWN' ) );
	}

	public function test_external_ref(): void {
		$this->assertSame( 'wc_order_42', VEXPay_Helpers::external_ref_for_order( 42 ) );
	}

	public function test_format_connection_success(): void {
		$msg = VEXPay_Helpers::format_connection_success( 12, 36.5 );
		$this->assertStringContainsString( '12', $msg );
		$this->assertStringContainsString( '36.5000', $msg );
		$this->assertStringContainsString( 'VEXPay', $msg );
	}
}
