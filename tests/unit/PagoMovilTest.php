<?php
/**
 * Pago Móvil gateway: hosted session, payments in several transfers, polling.
 *
 * @package VEXPay_Gateway
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fake API client: records requests, returns canned sessions and payments.
 */
final class VEXPay_Test_PM_Client extends VEXPay_API_Client {
	public array $created  = array();
	public array $sessions = array();
	public array $payments = array();

	public function __construct() {
		parent::__construct( 'https://api.vexpay.test', 'vk_test_abc' );
	}

	public function create_checkout_session( array $body ) {
		$this->created[] = $body;
		return array(
			'id'     => 'cs_new',
			'url'    => 'https://pay.vexpay.test/cs_new',
			'status' => 'open',
		);
	}

	public function get_checkout_session( string $session_id ) {
		return $this->sessions[ $session_id ] ?? new WP_Error( 'not_found', 'not found' );
	}

	public function get_payment( string $payment_id ) {
		return $this->payments[ $payment_id ] ?? new WP_Error( 'not_found', 'not found' );
	}
}

/**
 * Gateway wired to the fake client.
 */
final class VEXPay_Test_PM_Gateway extends VEXPay_Gateway_Pago_Movil {
	public VEXPay_Test_PM_Client $client;

	public function get_api_client(): VEXPay_API_Client {
		return $this->client;
	}
}

final class PagoMovilTest extends TestCase {

	protected function setUp(): void {
		vexpay_test_reset();
		update_option(
			'woocommerce_vexpay_settings',
			array(
				'testmode'     => 'yes',
				'test_api_key' => 'vk_test_abc',
			)
		);
	}

	private function gateway(): VEXPay_Test_PM_Gateway {
		$gateway          = new VEXPay_Test_PM_Gateway();
		$gateway->enabled = 'yes';
		$gateway->client  = new VEXPay_Test_PM_Client();
		return $gateway;
	}

	private function order( int $id = 7 ): WC_Order {
		$order = new WC_Order( $id, array(), 5.0 );
		$order->update_meta_data( '_payment_method', 'vexpay_pago_movil' );
		$order->update_meta_data( VEXPay_Gateway_Pago_Movil::META_SESSION_ID, 'cs_1' );
		return $order;
	}

	private function pending( float $paid, float $missing ): array {
		return array(
			'paymentId'        => 'pay_1',
			'status'           => 'PENDING',
			'vesAmount'        => $paid + $missing,
			'paidVesAmount'    => $paid,
			'missingVesAmount' => $missing,
			'expiresAt'        => '2026-11-24T12:00:00.000Z',
		);
	}

	public function test_helper_pending_notes_once_per_amount(): void {
		$first = VEXPay_Helpers::pago_movil_order_update( $this->pending( 1500, 337.53 ), 'payment.pending', false, '' );
		$this->assertSame( 'hold', $first['action'] );
		$this->assertSame( '1500.00', $first['paid'] );
		$this->assertStringContainsString( 'received Bs 1,500.00, missing Bs 337.53', $first['note'] );
		$this->assertStringContainsString( 'Open until 2026-11-24 12:00 UTC', $first['note'] );

		$again = VEXPay_Helpers::pago_movil_order_update( $this->pending( 1500, 337.53 ), 'payment.pending', false, '1500.00' );
		$this->assertSame( 'hold', $again['action'] );
		$this->assertSame( '', $again['note'] );
	}

	public function test_helper_status_from_event_and_paid_orders_untouched(): void {
		$data = $this->pending( 100, 50 );
		unset( $data['status'] );
		$this->assertSame( 'hold', VEXPay_Helpers::pago_movil_order_update( $data, 'payment.pending', false, '' )['action'] );
		$this->assertSame( 'none', VEXPay_Helpers::pago_movil_order_update( array( 'status' => 'FAILED' ), '', true, '100.00' )['action'] );
		$this->assertSame( 'none', VEXPay_Helpers::pago_movil_order_update( array( 'status' => 'PENDING' ), '', false, '' )['action'] );
	}

	public function test_helper_expired_with_and_without_money(): void {
		$refund = VEXPay_Helpers::pago_movil_order_update(
			array( 'status' => 'FAILED', 'failureCode' => 'expired', 'paidVesAmount' => 1500, 'refundPending' => true ),
			'payment.failed',
			false,
			'1500.00'
		);
		$this->assertSame( 'cancel', $refund['action'] );
		$this->assertStringContainsString( 'refunds the Bs 1,500.00', $refund['note'] );

		// A later read says 0 (the transfers were released): the noted amount still counts.
		$released = VEXPay_Helpers::pago_movil_order_update( array( 'status' => 'FAILED', 'paidVesAmount' => '0.00' ), '', false, '1500.00' );
		$this->assertSame( 'cancel', $released['action'] );

		$unpaid = VEXPay_Helpers::pago_movil_order_update( array( 'status' => 'FAILED', 'failureCode' => 'expired' ), 'payment.failed', false, '' );
		$this->assertSame( 'fail', $unpaid['action'] );
		$this->assertSame( 'VEX Pay Pago Móvil: expired', $unpaid['note'] );
	}

	public function test_two_transfers_hold_then_pay(): void {
		$gateway = $this->gateway();
		$order   = $this->order();

		$gateway->apply_payment_result( $order, $this->pending( 1500, 337.53 ), 'payment.pending' );
		$this->assertSame( 'on-hold', $order->status );
		$this->assertCount( 1, $order->notes );

		$gateway->apply_payment_result( $order, $this->pending( 1500, 337.53 ), 'payment.pending' );
		$this->assertCount( 1, $order->notes, 'a repeated webhook adds no note' );

		$gateway->apply_payment_result(
			$order,
			array(
				'paymentId' => 'pay_1',
				'status'    => 'COMPLETED',
				'vesAmount' => 1837.53,
				'transfers' => array(
					array( 'reference' => '0001', 'vesAmount' => 1500, 'at' => '2026-11-23T10:00:00Z' ),
					array( 'reference' => '0002', 'vesAmount' => 337.53, 'at' => '2026-11-23T10:20:00Z' ),
				),
			),
			'payment.completed'
		);
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 'pay_1', $order->txn );
		$this->assertStringContainsString( 'References: 0001, 0002.', end( $order->notes ) );

		$gateway->apply_payment_result( $order, array( 'status' => 'FAILED' ), 'payment.failed' );
		$this->assertSame( 'processing', $order->status, 'a paid order never fails' );
	}

	public function test_expired_with_money_cancels_order(): void {
		$gateway = $this->gateway();
		$order   = $this->order();
		$gateway->apply_payment_result( $order, $this->pending( 1500, 337.53 ), 'payment.pending' );
		$gateway->apply_payment_result( $order, array( 'status' => 'FAILED', 'failureCode' => 'expired', 'paidVesAmount' => 1500, 'refundPending' => true ), 'payment.failed' );
		$this->assertSame( 'cancelled', $order->status );
		$this->assertSame( 'EXPIRED_REFUND', $order->get_meta( VEXPay_Helpers::META_STATUS ) );
	}

	public function test_buyer_refund_request_cancels_with_its_own_note(): void {
		$gateway = $this->gateway();
		$order   = $this->order();
		$gateway->apply_payment_result( $order, $this->pending( 1000, 837.53 ), 'payment.pending' );
		$gateway->apply_payment_result( $order, array( 'status' => 'FAILED', 'failureCode' => 'refund_requested', 'paidVesAmount' => 1000, 'refundPending' => true ), 'payment.failed' );
		$this->assertSame( 'cancelled', $order->status );
		$this->assertStringContainsString( 'asked for the Bs 1,000.00', end( $order->notes ) );
		$this->assertStringContainsString( 'Devoluciones', end( $order->notes ) );
	}

	public function test_refund_updates_add_one_note_per_status_and_never_change_the_order(): void {
		$gateway = $this->gateway();
		$order   = $this->order();
		$gateway->apply_payment_result( $order, $this->pending( 1000, 837.53 ), 'payment.pending' );
		$gateway->apply_payment_result( $order, array( 'status' => 'FAILED', 'failureCode' => 'refund_requested', 'paidVesAmount' => 1000 ), 'payment.failed' );
		$notes = count( $order->notes );

		$base = array( 'refundRequestId' => 'rr_1', 'paymentId' => 'pay_1', 'paidVesAmount' => '1000.00', 'bankFeesVesAmount' => '5.98', 'refundVesAmount' => '994.02' );
		$gateway->apply_payment_result( $order, $base + array( 'status' => 'pending' ), 'payment.refund_requested' );
		$gateway->apply_payment_result( $order, $base + array( 'status' => 'pending' ), 'payment.refund_requested' );
		$this->assertCount( $notes + 1, $order->notes, 'a retried webhook adds no note' );
		$this->assertStringContainsString( 'Bs 994.02', end( $order->notes ) );
		$this->assertSame( 'cancelled', $order->status, 'a refund status is never read as a payment status' );

		$gateway->apply_payment_result( $order, $base + array( 'status' => 'sent', 'bankReferences' => array( '329912' ) ), 'payment.refund_updated' );
		$this->assertStringContainsString( 'References: 329912.', end( $order->notes ) );
		$this->assertSame( 'cancelled', $order->status );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_refund_note_texts(): void {
		$this->assertStringContainsString( 'declined', VEXPay_Helpers::pago_movil_refund_note( array( 'status' => 'declined', 'decisionNote' => 'ya enviado' ) ) );
		$this->assertStringContainsString( 'Note: ya enviado', VEXPay_Helpers::pago_movil_refund_note( array( 'status' => 'declined', 'decisionNote' => 'ya enviado' ) ) );
		$this->assertStringContainsString( 'under review', VEXPay_Helpers::pago_movil_refund_note( array( 'status' => 'needs_ops' ) ) );
		$this->assertSame( '', VEXPay_Helpers::pago_movil_refund_note( array( 'status' => 'weird' ) ) );
	}

	public function test_process_payment_creates_pago_movil_session(): void {
		$gateway = $this->gateway();
		$order   = new WC_Order( 9, array(), 12.5 );
		$order->meta[ VEXPay_Gateway_Pago_Movil::META_SESSION_ID ] = '';

		$result = $gateway->process_payment( 9 );
		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'https://pay.vexpay.test/cs_new', $result['redirect'] );
		$this->assertSame( array( 'pago_movil' ), $gateway->client->created[0]['methods'] );
		$this->assertSame( 'wc_order_9', $gateway->client->created[0]['reference'] );
		$this->assertSame( 'cs_new', $order->get_meta( VEXPay_Gateway_Pago_Movil::META_SESSION_ID ) );
		$this->assertSame( 'pending', $order->status );
	}

	public function test_process_payment_reuses_open_session(): void {
		$gateway = $this->gateway();
		$order   = $this->order( 9 );
		$gateway->client->sessions['cs_1'] = array(
			'id'     => 'cs_1',
			'status' => 'open',
			'url'    => 'https://pay.vexpay.test/cs_1',
		);
		$result = $gateway->process_payment( 9 );
		$this->assertSame( 'https://pay.vexpay.test/cs_1', $result['redirect'] );
		$this->assertCount( 0, $gateway->client->created );
	}

	public function test_sync_reads_progress_then_payment(): void {
		$gateway = $this->gateway();
		$order   = $this->order();

		$gateway->client->sessions['cs_1'] = array(
			'status'            => 'open',
			'paymentInProgress' => array(
				'paidVesAmount'    => '1500.00',
				'missingVesAmount' => '337.53',
				'expiresAt'        => '2026-11-24T12:00:00.000Z',
			),
		);
		$this->assertTrue( $gateway->sync_from_session( $order, $gateway->client ) );
		$this->assertSame( 'on-hold', $order->status );

		$gateway->client->sessions['cs_1'] = array(
			'status'    => 'paid',
			'paymentId' => 'pay_1',
		);
		$gateway->client->payments['pay_1'] = array(
			'paymentId' => 'pay_1',
			'status'    => 'COMPLETED',
			'vesAmount' => 1837.53,
		);
		$gateway->sync_from_session( $order, $gateway->client );
		$this->assertTrue( $order->is_paid() );
	}

	public function test_sync_unpaid_expired_session_fails_order(): void {
		$gateway                           = $this->gateway();
		$order                             = $this->order();
		$gateway->client->sessions['cs_1'] = array( 'status' => 'expired' );
		$gateway->sync_from_session( $order, $gateway->client );
		$this->assertSame( 'failed', $order->status );
	}

	public function test_poller_polls_pago_movil_orders_with_cooldown(): void {
		$gateway                            = $this->gateway();
		$order                              = $this->order();
		$gateway->client->sessions['cs_1']  = array( 'status' => 'paid', 'paymentId' => 'pay_1' );
		$gateway->client->payments['pay_1'] = array( 'paymentId' => 'pay_1', 'status' => 'COMPLETED', 'vesAmount' => 10 );

		$now = time();
		$this->assertSame( 1, VEXPay_Poller::run_pago_movil( $gateway, $now ) );
		$this->assertTrue( $order->is_paid() );

		$other = $this->order( 8 );
		$other->update_meta_data( VEXPay_Helpers::META_LAST_POLLED_AT, (string) $now );
		$this->assertSame( 0, VEXPay_Poller::run_pago_movil( $gateway, $now ), 'recently polled orders wait' );

		$gateway->enabled = 'no';
		$this->assertSame( 0, VEXPay_Poller::run_pago_movil( $gateway, $now + 600 ) );
	}

	public function test_hold_stock_never_cancels_pago_movil_orders(): void {
		$this->assertFalse( VEXPay_Gateway_Pago_Movil::filter_cancel_unpaid_order( true, $this->order() ) );
		$other = new WC_Order( 3 );
		$other->update_meta_data( '_payment_method', 'vexpay' );
		$this->assertTrue( VEXPay_Gateway_Pago_Movil::filter_cancel_unpaid_order( true, $other ) );
	}

	public function test_hidden_without_api_key(): void {
		update_option( 'woocommerce_vexpay_settings', array( 'testmode' => 'yes' ) );
		$this->assertFalse( $this->gateway()->is_available() );
	}
}
