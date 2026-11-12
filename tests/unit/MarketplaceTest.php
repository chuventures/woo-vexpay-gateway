<?php
/**
 * Marketplace mode tests (7.2–7.6).
 *
 * @package VEXPay_Gateway
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Débito gateway with an injected API client.
 */
final class Test_Debit_Gateway extends VEXPay_Gateway {
	/** @var VEXPay_API_Client */
	public $client;

	public function get_api_client(): VEXPay_API_Client {
		return $this->client;
	}
}

/**
 * VPOS gateway with an injected API client.
 */
final class Test_VPOS_Gateway extends VEXPay_Gateway_VPOS {
	/** @var VEXPay_API_Client */
	public $client;

	public function get_api_client(): VEXPay_API_Client {
		return $this->client;
	}
}

/**
 * Tests for VEXPay_Marketplace and its gateway wiring.
 */
final class MarketplaceTest extends TestCase {

	private const A = '11111111-1111-4111-8111-111111111111';
	private const B = '22222222-2222-4222-8222-222222222222';

	protected function setUp(): void {
		vexpay_test_reset();
		$_POST = array();
	}

	private function enable( bool $on = true ): void {
		update_option(
			'woocommerce_vexpay_settings',
			array(
				'testmode'           => 'yes',
				'test_api_key'       => 'vk_test_abc',
				'marketplace_splits' => $on ? 'yes' : 'no',
			)
		);
	}

	private function map_product( int $product_id, string $merchant_id ): void {
		$GLOBALS['vexpay_test']['post_meta'][ $product_id ][ VEXPay_Marketplace::PRODUCT_META ] = $merchant_id;
	}

	/**
	 * Order: US$60 plan from A (product 10), US$30 plan from B (product 20), US$10 shipping.
	 */
	private function two_comercio_order( float $discount = 1.0, int $id = 101 ): WC_Order {
		$this->map_product( 10, self::A );
		$this->map_product( 20, self::B );
		$items = array(
			new WC_Order_Item_Product( array( 'product_id' => 10, 'total' => 60 * $discount ) ),
			new WC_Order_Item_Product( array( 'product_id' => 20, 'total' => 30 * $discount ) ),
		);
		return new WC_Order( $id, $items, 90 * $discount + 10 );
	}

	private function api_error( int $status, $message, array $body = array() ): WP_Error {
		$body = array_merge(
			array(
				'statusCode' => $status,
				'message'    => $message,
			),
			$body
		);
		return new WP_Error(
			'vexpay_api_error',
			is_string( $message ) ? $message : 'VEXPay API request failed.',
			array(
				'status' => $status,
				'body'   => $body,
			)
		);
	}

	/*
	 * 7.2 — line-item resolution and share computation.
	 */

	public function test_two_comercios_and_shipping_debit(): void {
		$this->enable();
		$order  = $this->two_comercio_order();
		$splits = VEXPay_Marketplace::prepare_order_splits( $order, 'debit', 100.0, 3650.0 );

		$this->assertSame(
			array(
				array(
					'merchantId' => self::A,
					'amount'     => '2190.00',
					'reference'  => 'wc_order_101',
				),
				array(
					'merchantId' => self::B,
					'amount'     => '1095.00',
					'reference'  => 'wc_order_101',
				),
			),
			$splits
		);
		$record = $order->get_meta( '_vexpay_splits' );
		$this->assertSame( 'debit', $record['method'] );
		$this->assertSame( 'VES', $record['currency'] );
		$this->assertSame(
			array(
				self::A => '60.00',
				self::B => '30.00',
			),
			$record['usd']
		);
	}

	public function test_cart_coupon_shares_follow_discounted_line_totals(): void {
		$this->enable();
		$order = $this->two_comercio_order( 0.9 ); // 10% cart coupon → 54 + 27 + 10 shipping = 91.
		$ves   = round( 91 * 36.5, 2 );
		$out   = VEXPay_Marketplace::prepare_order_splits( $order, 'debit', 91.0, $ves );

		$this->assertSame( '1971.00', $out[0]['amount'] ); // 54 × 36.5.
		$this->assertSame( '985.50', $out[1]['amount'] );  // 27 × 36.5.
		$this->assertSame( array( self::A => '54.00', self::B => '27.00' ), $order->get_meta( '_vexpay_splits' )['usd'] );
	}

	public function test_unmapped_item_stays_with_marketplace(): void {
		$this->enable();
		$this->map_product( 10, self::A );
		$order = new WC_Order(
			7,
			array(
				new WC_Order_Item_Product( array( 'product_id' => 10, 'total' => 40 ) ),
				new WC_Order_Item_Product( array( 'product_id' => 99, 'total' => 25 ) ),
			),
			65.0
		);
		$out = VEXPay_Marketplace::prepare_order_splits( $order, 'debit', 65.0, 650.0 );

		$this->assertCount( 1, $out );
		$this->assertSame( self::A, $out[0]['merchantId'] );
		$this->assertSame( '400.00', $out[0]['amount'] );
	}

	public function test_line_tax_is_part_of_the_share(): void {
		$this->enable();
		$this->map_product( 10, self::A );
		$order = new WC_Order( 8, array( new WC_Order_Item_Product( array( 'product_id' => 10, 'total' => 50, 'tax' => 8 ) ) ), 58.0 );
		$out   = VEXPay_Marketplace::prepare_order_splits( $order, 'vpos', 58.0 );

		$this->assertSame( '58.00', $out[0]['amount'] );
	}

	public function test_rounding_is_down_and_never_exceeds_payment(): void {
		$lines = array(
			array( 'merchant_id' => 'mrc_a', 'total' => 3.33, 'tax' => 0 ),
			array( 'merchant_id' => 'mrc_b', 'total' => 3.33, 'tax' => 0 ),
			array( 'merchant_id' => 'mrc_c', 'total' => 3.34, 'tax' => 0 ),
		);
		$ves   = round( 10 * 36.4567, 2 ); // 364.57.
		$out   = VEXPay_Marketplace::compute_shares( $lines, 'debit', 10.0, $ves );

		// 3.33 × 364.57 / 10 = 121.40181 → 121.40 (down); 3.34 × … = 121.76638 → 121.76.
		$this->assertSame( array( '121.40', '121.40', '121.76' ), array_column( $out['splits'], 'amount' ) );
		$this->assertLessThanOrEqual( $ves, array_sum( array_map( 'floatval', array_column( $out['splits'], 'amount' ) ) ) );
	}

	public function test_floor2_guards_float_error(): void {
		$this->assertSame( 2190.0, VEXPay_Marketplace::floor2( 60 * 3650 / 100 ) );
		$this->assertSame( 0.29, VEXPay_Marketplace::floor2( 0.29 ) );
		$this->assertSame( 1.0, VEXPay_Marketplace::floor2( 0.1 * 3 / 0.3 ) );
		$this->assertSame( 121.40, VEXPay_Marketplace::floor2( 121.40181 ) );
	}

	public function test_shares_scaled_down_when_lines_exceed_total(): void {
		// A negative fee line (e.g. store credit) makes the order total lower than the lines.
		$lines = array(
			array( 'merchant_id' => 'mrc_a', 'total' => 60, 'tax' => 0 ),
			array( 'merchant_id' => 'mrc_b', 'total' => 40, 'tax' => 0 ),
		);
		$out   = VEXPay_Marketplace::compute_shares( $lines, 'vpos', 50.0 );
		$this->assertSame( array( '30.00', '20.00' ), array_column( $out['splits'], 'amount' ) );
	}

	public function test_line_item_filter_wins_over_empty_field(): void {
		$this->enable();
		add_filter(
			'vexpay_line_item_merchant_id',
			static function ( $merchant_id, $item, $order ) {
				return 30 === $item->get_product_id() ? 'mrc_custom1' : $merchant_id;
			},
			10,
			3
		);
		$order = new WC_Order( 9, array( new WC_Order_Item_Product( array( 'product_id' => 30, 'total' => 20 ) ) ), 20.0 );
		$out   = VEXPay_Marketplace::prepare_order_splits( $order, 'vpos', 20.0 );

		$this->assertSame( 'mrc_custom1', $out[0]['merchantId'] );
	}

	public function test_variation_field_overrides_parent(): void {
		$this->map_product( 40, self::A );
		$this->map_product( 41, self::B );
		$this->assertSame( self::B, VEXPay_Marketplace::field_merchant_id( 40, 41 ) );
		$this->assertSame( self::A, VEXPay_Marketplace::field_merchant_id( 40, 42 ) );
		$this->assertSame( '', VEXPay_Marketplace::field_merchant_id( 43, 0 ) );
	}

	public function test_order_splits_filter_can_add_commission(): void {
		$this->enable();
		add_filter(
			'vexpay_order_splits',
			static function ( $splits, $order, $method ) {
				foreach ( $splits as &$share ) {
					$share['applicationFeePercent'] = 20;
				}
				return $splits;
			},
			10,
			3
		);
		$out = VEXPay_Marketplace::prepare_order_splits( $this->two_comercio_order(), 'debit', 100.0, 3650.0 );
		$this->assertSame( 20.0, $out[0]['applicationFeePercent'] );
	}

	public function test_order_splits_filter_cannot_exceed_payment(): void {
		$this->enable();
		add_filter(
			'vexpay_order_splits',
			static function () {
				return array( array( 'merchantId' => 'mrc_a', 'amount' => '3650.01' ) );
			}
		);
		$out = VEXPay_Marketplace::prepare_order_splits( $this->two_comercio_order(), 'debit', 100.0, 3650.0 );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'vexpay_split_sum', $out->get_error_code() );
	}

	public function test_more_than_ten_comercios_is_refused(): void {
		$lines = array();
		for ( $i = 0; $i < 11; $i++ ) {
			$lines[] = array( 'merchant_id' => 'mrc_' . $i, 'total' => 1, 'tax' => 0 );
		}
		$out = VEXPay_Marketplace::compute_shares( $lines, 'vpos', 11.0 );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'vexpay_split_too_many', $out->get_error_code() );
	}

	public function test_mode_off_computes_and_stores_nothing(): void {
		$this->enable( false );
		$order = $this->two_comercio_order();
		$this->assertSame( array(), VEXPay_Marketplace::prepare_order_splits( $order, 'debit', 100.0, 3650.0 ) );
		$this->assertArrayNotHasKey( '_vexpay_splits', $order->meta );
	}

	/*
	 * 7.3 — sending splits and refusals.
	 */

	public function test_with_splits_off_leaves_body_unchanged(): void {
		$this->enable();
		$order = $this->two_comercio_order();
		VEXPay_Marketplace::prepare_order_splits( $order, 'debit', 100.0, 3650.0 );

		$this->enable( false ); // Switched off after the order was prepared.
		$body = array(
			'banco' => '0102',
			'monto' => 3650.0,
		);
		$this->assertSame( $body, VEXPay_Marketplace::with_splits( $body, $order, 'debit' ) );
	}

	public function test_with_splits_only_for_matching_method(): void {
		$this->enable();
		$order = $this->two_comercio_order();
		VEXPay_Marketplace::prepare_order_splits( $order, 'debit', 100.0, 3650.0 );
		$this->assertArrayNotHasKey( 'splits', VEXPay_Marketplace::with_splits( array(), $order, 'vpos' ) );
		$this->assertCount( 2, VEXPay_Marketplace::with_splits( array(), $order, 'debit' )['splits'] );
	}

	private function debit_gateway( VEXPay_API_Client $client ): Test_Debit_Gateway {
		$gateway         = new Test_Debit_Gateway();
		$gateway->client = $client;
		return $gateway;
	}

	public function test_debit_execute_body_carries_splits(): void {
		$this->enable();
		$order = $this->two_comercio_order();
		VEXPay_Marketplace::prepare_order_splits( $order, 'debit', 100.0, 3650.0 );

		$client = $this->createMock( VEXPay_API_Client::class );
		$client->expects( $this->once() )
			->method( 'debit_execute' )
			->with(
				$this->callback(
					static function ( array $body ): bool {
						return isset( $body['splits'] )
							&& 2 === count( $body['splits'] )
							&& '2190.00' === $body['splits'][0]['amount']
							&& self::A === $body['splits'][0]['merchantId']
							&& 3650.0 === $body['monto'];
					}
				)
			)
			->willReturn(
				array(
					'paymentId' => 'pay_1',
					'status'    => 'COMPLETED',
					'splits'    => array(
						array(
							'merchantId'          => self::A,
							'merchantExternalRef' => 'prov_a',
							'amountVes'           => '2190.00',
							'applicationFeeVes'   => '438.00',
							'feeVes'              => '65.70',
							'netVes'              => '1686.30',
							'reference'           => 'wc_order_101',
							'unknown'             => 'dropped',
						),
					),
				)
			);

		$gateway = $this->debit_gateway( $client );
		$body    = VEXPay_Marketplace::with_splits( array( 'monto' => 3650.0 ), $order, 'debit' );
		$result  = $gateway->execute_debit( $order, $body );
		$gateway->apply_payment_result( $order, $result );

		$this->assertTrue( $order->is_paid() );
		$reported = $order->get_meta( '_vexpay_splits_reported' );
		$this->assertSame( '1686.30', $reported[0]['netVes'] );
		$this->assertArrayNotHasKey( 'unknown', $reported[0] );
	}

	public function test_debit_split_refusal_takes_no_money_and_is_not_retried(): void {
		$this->enable();
		$GLOBALS['vexpay_test']['transients'][ 'vexpay_merchants_' . md5( 'vk_test_abc' ) ] = array(
			self::A => array( 'name' => 'Provider A', 'externalRef' => 'prov_a' ),
		);
		$order = $this->two_comercio_order();
		VEXPay_Marketplace::prepare_order_splits( $order, 'debit', 100.0, 3650.0 );

		$client = $this->createMock( VEXPay_API_Client::class );
		$client->expects( $this->once() ) // Exactly once: no retry without splits.
			->method( 'debit_execute' )
			->willReturn( $this->api_error( 400, array( 'splits.0.merchantId: merchant 1111 is inactive' ) ) );

		$gateway = $this->debit_gateway( $client );
		$body    = VEXPay_Marketplace::with_splits( array( 'monto' => 3650.0 ), $order, 'debit' );
		$result  = $gateway->execute_debit( $order, $body );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'vexpay_split_refused', $result->get_error_code() );
		$this->assertSame( VEXPay_Marketplace::customer_refusal_message(), $result->get_error_message() );
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( 'failed', $order->status );
		$this->assertStringContainsString( 'Provider A', implode( "\n", $order->notes ) );
		$this->assertStringContainsString( 'inactive', implode( "\n", $order->notes ) );
		$this->assertNotEmpty( $GLOBALS['vexpay_test']['logs'] );
	}

	public function test_refusal_message_is_neutral_and_filterable(): void {
		$order = new WC_Order( 81 );
		$this->assertSame(
			'Este pedido no se puede pagar en este momento. Intenta de nuevo o contacta a la tienda.',
			VEXPay_Marketplace::customer_refusal_message( $order )
		);

		add_filter(
			'vexpay_split_refusal_message',
			static function ( $message, $o ) {
				return 81 === $o->get_id() ? 'Este plan no está disponible para pago en este momento' : $message;
			},
			10,
			2
		);
		$this->assertSame( 'Este plan no está disponible para pago en este momento', VEXPay_Marketplace::customer_refusal_message( $order ) );
		$this->assertStringStartsWith( 'Este pedido', VEXPay_Marketplace::customer_refusal_message( new WC_Order( 82 ) ) );
	}

	public function test_refusal_message_filter_cannot_blank_it(): void {
		add_filter( 'vexpay_split_refusal_message', static fn() => '   ' );
		$this->assertStringStartsWith( 'Este pedido', VEXPay_Marketplace::customer_refusal_message() );
	}

	public function test_split_refused_at_otp_submit_redirects_to_checkout_with_notice(): void {
		$this->enable();
		$order = $this->two_comercio_order();
		VEXPay_Marketplace::prepare_order_splits( $order, 'debit', 100.0, 3650.0 );

		$client = $this->createMock( VEXPay_API_Client::class );
		$client->expects( $this->once() )
			->method( 'debit_execute' )
			->willReturn( $this->api_error( 400, array( 'splits.0.merchantId: merchant is inactive' ) ) );

		$gateway = $this->debit_gateway( $client );
		$result  = $gateway->execute_debit( $order, VEXPay_Marketplace::with_splits( array( 'monto' => 3650.0 ), $order, 'debit' ) );
		$this->assertSame( 'vexpay_split_refused', $result->get_error_code() );
		$this->assertSame( 'failed', $order->status );

		// A guest posting the OTP form may have no session cookie yet; the notice must still persist.
		WC()->session = new VEXPay_Test_Session();

		// handle_otp_submit() redirects here (then exits) instead of the failed order's OTP page.
		$this->assertSame( 'https://shop.test/checkout/', $gateway->split_refused_redirect_url( $order ) );
		$this->assertTrue( WC()->session->cookie_set );
		$this->assertSame(
			array( 'error', VEXPay_Marketplace::customer_refusal_message( $order ) ),
			end( $GLOBALS['vexpay_test']['notices'] )
		);
	}

	public function test_non_split_400_is_not_a_split_refusal(): void {
		$this->assertFalse( VEXPay_Marketplace::is_split_rejection( $this->api_error( 400, 'Invalid OTP' ) ) );
		$this->assertFalse( VEXPay_Marketplace::is_split_rejection( $this->api_error( 422, 'splits invalid' ) ) );
		$this->assertTrue( VEXPay_Marketplace::is_split_rejection( $this->api_error( 400, 'splits: sum exceeds payment amount' ) ) );
		$this->assertTrue( VEXPay_Marketplace::is_split_rejection( $this->api_error( 400, 'Bad Request', array( 'field' => 'splits' ) ) ) );
	}

	private function post_card(): void {
		$_POST = array(
			'vexpay_vpos_card_number'      => '4111 1111 1111 1111',
			'vexpay_vpos_expiry'           => '12/30',
			'vexpay_vpos_cvv'              => '123',
			'vexpay_vpos_brand'            => '1',
			'vexpay_vpos_account_type'     => '0',
			'vexpay_vpos_holder_name'      => 'Ana Perez',
			'vexpay_vpos_holder_id_type'   => 'V',
			'vexpay_vpos_holder_id_number' => '12345678',
		);
	}

	private function vpos_gateway( VEXPay_API_Client $client ): Test_VPOS_Gateway {
		$gateway         = new Test_VPOS_Gateway();
		$gateway->client = $client;
		return $gateway;
	}

	/**
	 * Exact 1.2.2 VPOS body for order 101.
	 */
	private function expected_122_vpos_body(): array {
		return array(
			'usdAmount'       => 100.0,
			'cardNumber'      => '4111111111111111',
			'expirationMonth' => 12,
			'expirationYear'  => 2030,
			'cvv'             => '123',
			'cardHolderName'  => 'Ana Perez',
			'cardHolderId'    => 'V12345678',
			'accountType'     => 0,
			'cardType'        => 1,
			'externalRef'     => 'wc_order_101',
		);
	}

	public function test_vpos_mode_off_body_is_byte_for_byte_122(): void {
		$this->enable( false );
		$this->post_card();
		$order  = $this->two_comercio_order();
		$client = $this->createMock( VEXPay_API_Client::class );
		$client->expects( $this->once() )
			->method( 'execute_vpos' )
			->with( $this->identicalTo( $this->expected_122_vpos_body() ) )
			->willReturn( array( 'paymentId' => 'pay_v', 'status' => 'COMPLETED' ) );

		$result = $this->vpos_gateway( $client )->process_payment( 101 );
		$this->assertSame( 'success', $result['result'] );
		$this->assertArrayNotHasKey( '_vexpay_splits', $order->meta );
	}

	public function test_vpos_body_carries_usd_splits(): void {
		$this->enable();
		$this->post_card();
		$order    = $this->two_comercio_order();
		$expected = $this->expected_122_vpos_body();
		$expected['splits'] = array(
			array( 'merchantId' => self::A, 'amount' => '60.00', 'reference' => 'wc_order_101' ),
			array( 'merchantId' => self::B, 'amount' => '30.00', 'reference' => 'wc_order_101' ),
		);

		$client = $this->createMock( VEXPay_API_Client::class );
		$client->expects( $this->once() )
			->method( 'execute_vpos' )
			->with( $this->identicalTo( $expected ) )
			->willReturn( array( 'paymentId' => 'pay_v', 'status' => 'COMPLETED' ) );

		$result = $this->vpos_gateway( $client )->process_payment( 101 );
		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'USD', $order->get_meta( '_vexpay_splits' )['currency'] );
	}

	public function test_vpos_split_refusal_takes_no_money(): void {
		$this->enable();
		$this->post_card();
		$order  = $this->two_comercio_order();
		$client = $this->createMock( VEXPay_API_Client::class );
		$client->expects( $this->once() )
			->method( 'execute_vpos' )
			->willReturn( $this->api_error( 400, array( 'splits: merchant belongs to another tenant' ) ) );

		$result = $this->vpos_gateway( $client )->process_payment( 101 );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( 'failed', $order->status );
		$this->assertSame( 0, $GLOBALS['vexpay_test']['cart_empty'] );
		$this->assertSame( array( 'error', VEXPay_Marketplace::customer_refusal_message() ), $GLOBALS['vexpay_test']['notices'][0] );
	}

	public function test_too_many_comercios_refused_before_any_api_call(): void {
		$this->enable();
		$this->post_card();
		$items = array();
		for ( $i = 0; $i < 11; $i++ ) {
			$this->map_product( 200 + $i, 'mrc_' . $i );
			$items[] = new WC_Order_Item_Product( array( 'product_id' => 200 + $i, 'total' => 5 ) );
		}
		$order  = new WC_Order( 55, $items, 55.0 );
		$client = $this->createMock( VEXPay_API_Client::class );
		$client->expects( $this->never() )->method( 'execute_vpos' );

		$result = $this->vpos_gateway( $client )->process_payment( 55 );
		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( 'failed', $order->status );
	}

	public function test_capture_reported_splits_noop_without_splits(): void {
		$order = new WC_Order( 70 );
		$this->assertFalse( VEXPay_Marketplace::capture_reported_splits( $order, array( 'status' => 'COMPLETED' ) ) );
		$this->assertArrayNotHasKey( '_vexpay_splits_reported', $order->meta );
		$this->assertSame( 0, $order->saves );
	}

	/*
	 * 7.4 — USDT hidden for comercio carts.
	 */

	private function usdt_gateway(): VEXPay_Gateway_USDT {
		$GLOBALS['vexpay_test']['transients'][ 'vexpay_usdt_ok_' . md5( 'vk_test_abc' ) ] = 'yes';
		$gateway          = new VEXPay_Gateway_USDT();
		$gateway->enabled = 'yes';
		return $gateway;
	}

	private function cart_with( int $product_id ): void {
		$GLOBALS['vexpay_test']['cart'] = array(
			'k1' => array(
				'product_id'   => $product_id,
				'variation_id' => 0,
				'data'         => new WC_Product( $product_id ),
			),
		);
	}

	public function test_usdt_hidden_for_comercio_cart_when_mode_on(): void {
		$this->enable();
		$this->map_product( 10, self::A );
		$this->cart_with( 10 );
		$this->assertFalse( $this->usdt_gateway()->is_available() );
	}

	public function test_usdt_offered_when_mode_off_or_no_comercio_item(): void {
		$this->enable( false );
		$this->map_product( 10, self::A );
		$this->cart_with( 10 );
		$this->assertTrue( $this->usdt_gateway()->is_available() );

		$this->enable();
		$this->cart_with( 99 );
		$this->assertTrue( $this->usdt_gateway()->is_available() );
	}

	public function test_usdt_hidden_via_cart_filter(): void {
		$this->enable();
		$this->cart_with( 99 );
		add_filter(
			'vexpay_cart_item_merchant_id',
			static function () {
				return 'mrc_custom';
			}
		);
		$this->assertFalse( $this->usdt_gateway()->is_available() );
	}

	/*
	 * 7.5 — order box data.
	 */

	public function test_order_box_shows_shares_and_reported_nets_escaped(): void {
		$this->enable();
		$GLOBALS['vexpay_test']['transients'][ 'vexpay_merchants_' . md5( 'vk_test_abc' ) ] = array(
			self::A => array( 'name' => 'Provider <b>A</b>', 'externalRef' => 'prov_a' ),
		);
		$order = $this->two_comercio_order();
		VEXPay_Marketplace::prepare_order_splits( $order, 'debit', 100.0, 3650.0 );
		$order->update_meta_data( VEXPay_Helpers::META_VES_AMOUNT, '3650.00' );

		$html = VEXPay_Marketplace_Admin::order_split_html( $order );
		$this->assertStringContainsString( 'Provider &lt;b&gt;A&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( '<b>A</b>', $html );
		$this->assertStringContainsString( 'Bs 2,190.00 (US$60.00)', $html );
		$this->assertStringContainsString( 'Bs 365.00', $html ); // Remainder: 3650 − 2190 − 1095.
		$this->assertStringContainsString( 'appear once VEX Pay reports', $html );

		VEXPay_Marketplace::capture_reported_splits(
			$order,
			array(
				'splits' => array(
					array(
						'merchantId'        => self::A,
						'amountVes'         => '2190.00',
						'applicationFeeVes' => '438.00',
						'feeVes'            => '65.70',
						'netVes'            => '1686.30',
					),
				),
			)
		);
		$rows = VEXPay_Marketplace_Admin::order_split_rows( $order );
		$this->assertTrue( $rows['reported'] );
		$this->assertSame( '1686.30', $rows['rows'][0]['net'] );
		$this->assertStringContainsString( 'Bs 1,686.30', VEXPay_Marketplace_Admin::order_split_html( $order ) );
	}

	/*
	 * Merchant list.
	 */

	public function test_get_merchants_paginates_and_caches(): void {
		$this->enable();
		$client = $this->createMock( VEXPay_API_Client::class );
		$client->expects( $this->exactly( 2 ) )
			->method( 'list_merchants' )
			->willReturnOnConsecutiveCalls(
				array(
					'items'      => array(
						array( 'merchantId' => self::A, 'name' => 'Provider A', 'externalRef' => 'prov_a', 'isActive' => true ),
					),
					'nextCursor' => 'c2',
				),
				array(
					'items'      => array(
						array( 'merchantId' => self::B, 'name' => 'Provider B', 'externalRef' => 'prov_b', 'isActive' => true ),
						array( 'merchantId' => 'mrc_off', 'name' => 'Off', 'isActive' => false ),
					),
					'nextCursor' => null,
				)
			);

		$list = VEXPay_Marketplace::get_merchants( false, $client );
		$this->assertSame( array( self::A, self::B ), array_keys( $list ) );
		$this->assertSame( 'Provider B', $list[ self::B ]['name'] );
		// Second call is served from the transient (the mock allows exactly two API calls).
		$this->assertSame( $list, VEXPay_Marketplace::get_merchants( false, $client ) );
	}

	/*
	 * 7.6 — vexpay_upsert_merchant().
	 */

	private function comercio(): array {
		return array(
			'external_ref'            => 'planazo_com_42',
			'name'                    => 'Provider A',
			'identification'          => 'J-12345678-9',
			'contact_email'           => 'a@example.com',
			'contact_phone'           => '04141234567',
			'bank_code'               => '0134',
			'phone'                   => '0414-555-5555',
			'application_fee_percent' => 20,
			'auto_payout'             => true,
		);
	}

	public function test_upsert_creates_then_updates_same_external_ref(): void {
		$this->enable();
		$client = $this->createMock( VEXPay_API_Client::class );

		$client->expects( $this->exactly( 2 ) )
			->method( 'create_merchant' )
			->with(
				$this->callback(
					static function ( array $body ): bool {
						return 'planazo_com_42' === $body['externalRef']
							&& 'J123456789' === $body['identification']
							&& '0134' === $body['bankCode']
							&& '04145555555' === $body['phone'];
					}
				)
			)
			->willReturnOnConsecutiveCalls(
				array( 'merchantId' => self::A, 'externalRef' => 'planazo_com_42' ),
				$this->api_error( 409, 'external_ref_conflict' )
			);

		$client->expects( $this->once() )
			->method( 'get_merchant_by_external_ref' )
			->with( 'planazo_com_42' )
			->willReturn(
				array(
					'merchantId'    => self::A,
					'payoutMethods' => array(
						array( 'bankCode' => '0102', 'destination' => '584140000000' ),
					),
				)
			);

		$client->expects( $this->once() )
			->method( 'add_merchant_payout_method' )
			->with( self::A, array( 'bankCode' => '0134', 'phone' => '04145555555' ) )
			->willReturn( array( 'payoutMethodId' => 'pm_2' ) );

		$patches = array();
		$client->expects( $this->exactly( 2 ) )
			->method( 'update_merchant' )
			->willReturnCallback(
				static function ( string $id, array $body ) use ( &$patches ) {
					$patches[] = array( $id, $body );
					return array( 'merchantId' => $id );
				}
			);

		$first = VEXPay_Marketplace::upsert_merchant( $this->comercio(), $client );

		$changed                            = $this->comercio();
		$changed['application_fee_percent'] = 22.5;
		$second                             = VEXPay_Marketplace::upsert_merchant( $changed, $client );

		$this->assertSame( self::A, $first );
		$this->assertSame( self::A, $second );
		$this->assertSame( array( self::A, array( 'applicationFeePercent' => 20.0, 'autoPayoutEnabled' => true ) ), $patches[0] );
		$this->assertSame( 22.5, $patches[1][1]['applicationFeePercent'] );
	}

	public function test_upsert_skips_payout_method_when_unchanged(): void {
		$this->enable();
		$client = $this->createMock( VEXPay_API_Client::class );
		$client->method( 'create_merchant' )->willReturn( $this->api_error( 409, 'external_ref_conflict' ) );
		$client->method( 'get_merchant_by_external_ref' )->willReturn(
			array(
				'merchantId'    => self::A,
				'payoutMethods' => array( array( 'bankCode' => '0134', 'destination' => '584145555555' ) ),
			)
		);
		$client->expects( $this->never() )->method( 'add_merchant_payout_method' );
		$client->method( 'update_merchant' )->willReturn( array() );

		$this->assertSame( self::A, VEXPay_Marketplace::upsert_merchant( $this->comercio(), $client ) );
	}

	public function test_upsert_requires_external_ref_and_passes_other_errors(): void {
		$client = $this->createMock( VEXPay_API_Client::class );
		$client->expects( $this->once() )->method( 'create_merchant' )->willReturn( $this->api_error( 400, array( 'identification must be V/E+8 or J/G+9' ) ) );

		$this->assertInstanceOf( WP_Error::class, VEXPay_Marketplace::upsert_merchant( array( 'name' => 'x' ), $client ) );
		$this->assertSame( 400, VEXPay_Marketplace::upsert_merchant( $this->comercio(), $client )->get_error_data()['status'] );
	}

	public function test_global_helper_exists(): void {
		$this->assertTrue( function_exists( 'vexpay_upsert_merchant' ) );
	}
}
