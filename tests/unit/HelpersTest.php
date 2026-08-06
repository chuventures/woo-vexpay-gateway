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

	public function test_debtor_accounts_upsert_and_label(): void {
		$a = VEXPay_Helpers::profile_from_normalized( 'V12345678', '584121234567', '0102' );
		$b = VEXPay_Helpers::profile_from_normalized( 'V87654321', '584149333844', '0108' );

		$this->assertTrue( VEXPay_Helpers::debtor_profile_is_complete( $a ) );
		$list = VEXPay_Helpers::upsert_debtor_account( array(), $a );
		$this->assertCount( 1, $list );

		$list = VEXPay_Helpers::upsert_debtor_account( $list, $b );
		$this->assertCount( 2, $list );
		$this->assertSame( 'V', $list[0]['id_type'] );
		$this->assertSame( '87654321', $list[0]['id_number'] );

		// Re-saving A moves it to front and dedupes.
		$list = VEXPay_Helpers::upsert_debtor_account( $list, $a );
		$this->assertCount( 2, $list );
		$this->assertSame( '12345678', $list[0]['id_number'] );

		$label = VEXPay_Helpers::debtor_account_label( $a, 'Banco de Venezuela' );
		$this->assertStringContainsString( 'V-12.345.678', $label );
		$this->assertStringContainsString( '0412•••4567', $label );
		$this->assertStringContainsString( 'Banco de Venezuela', $label );
		$this->assertSame( 'V-18.819.897', VEXPay_Helpers::format_debtor_id_display( 'V', '18819897' ) );
		$this->assertSame( 'J-1.234.567', VEXPay_Helpers::format_debtor_id_display( 'J', '1234567' ) );
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
		$this->assertSame( 'complete', VEXPay_Helpers::map_payment_status( 'ACCP' ) );
		$this->assertSame( 'fail', VEXPay_Helpers::map_payment_status( 'FAILED' ) );
		$this->assertSame( 'fail', VEXPay_Helpers::map_payment_status( 'CANCELED' ) );
		$this->assertSame( 'refund', VEXPay_Helpers::map_payment_status( 'REVERSED' ) );
		$this->assertSame( 'hold', VEXPay_Helpers::map_payment_status( 'PENDING' ) );
		$this->assertSame( 'hold', VEXPay_Helpers::map_payment_status( 'AC00' ) );
		$this->assertSame( 'ignore', VEXPay_Helpers::map_payment_status( 'UNKNOWN' ) );
	}

	public function test_normalize_debit_execute_result(): void {
		$accp = VEXPay_Helpers::normalize_debit_execute_result(
			array(
				'code'      => 'ACCP',
				'paymentId' => 'pay-1',
				'reference' => 'ref-9',
			)
		);
		$this->assertSame( 'COMPLETED', $accp['status'] );
		$this->assertSame( 'ref-9', $accp['bankReference'] );

		$pending = VEXPay_Helpers::normalize_debit_execute_result( array( 'code' => 'AC00', 'id' => 'op-1' ) );
		$this->assertSame( 'PENDING', $pending['status'] );
		$this->assertSame( 'op-1', $pending['bankReference'] );
	}

	public function test_format_ves_amount(): void {
		$this->assertSame( 50.0, VEXPay_Helpers::format_ves_amount( 50 ) );
		$this->assertSame( 50.12, VEXPay_Helpers::format_ves_amount( 50.123 ) );
		$this->assertSame( 50.13, VEXPay_Helpers::format_ves_amount( '50.129' ) );
	}

	public function test_mask_phone_for_log(): void {
		$this->assertSame( '********4567', VEXPay_Helpers::mask_phone_for_log( '584121234567' ) );
		$this->assertSame( '****', VEXPay_Helpers::mask_phone_for_log( '12' ) );
	}

	public function test_debit_otp_accepted(): void {
		$this->assertTrue( VEXPay_Helpers::debit_otp_accepted( array( 'code' => '202', 'success' => true ) ) );
		$this->assertTrue( VEXPay_Helpers::debit_otp_accepted( array( 'code' => '00' ) ) );
		$this->assertFalse( VEXPay_Helpers::debit_otp_accepted( array() ) );
		$this->assertFalse( VEXPay_Helpers::debit_otp_accepted( array( 'code' => '91', 'message' => 'rejected' ) ) );
		$this->assertFalse( VEXPay_Helpers::debit_otp_accepted( array( 'success' => false, 'code' => '202' ) ) );
		$this->assertSame( 'rejected', VEXPay_Helpers::debit_otp_error_message( array( 'code' => '91', 'message' => 'rejected' ) ) );
	}

	public function test_api_key_kind_and_simulated_debit(): void {
		$this->assertSame( 'live', VEXPay_Helpers::api_key_kind( 'vk_live_abc' ) );
		$this->assertSame( 'test', VEXPay_Helpers::api_key_kind( 'vk_test_abc' ) );
		$this->assertSame( 'live', VEXPay_Helpers::api_key_kind( 'dev-local-api-key' ) );
		$this->assertSame( 'test', VEXPay_Helpers::api_key_kind( 'dev-local-api-key-test' ) );
		$this->assertSame( 'missing', VEXPay_Helpers::api_key_kind( '' ) );
		$this->assertSame( 'unknown', VEXPay_Helpers::api_key_kind( 'opaque-key' ) );

		$this->assertTrue( VEXPay_Helpers::debit_otp_is_simulated( true, 'vk_live_abc' ) );
		$this->assertTrue( VEXPay_Helpers::debit_otp_is_simulated( false, 'vk_test_abc' ) );
		$this->assertFalse( VEXPay_Helpers::debit_otp_is_simulated( false, 'vk_live_abc' ) );
	}

	public function test_describe_debit_otp_body(): void {
		$desc = VEXPay_Helpers::describe_debit_otp_body(
			array(
				'banco'    => '0191',
				'monto'    => 50.0,
				'telefono' => '584121234567',
				'cedula'   => 'V12345678',
			)
		);
		$this->assertStringContainsString( 'banco=0191(string)', $desc );
		$this->assertStringContainsString( 'monto=50.00(double)', $desc );
		$this->assertStringContainsString( 'telefono=********4567(len=12)', $desc );
		$this->assertStringContainsString( 'cedula=V********', $desc );
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
