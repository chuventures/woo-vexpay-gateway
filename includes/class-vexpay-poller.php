<?php
/**
 * Poll VEXPay for pending payments when webhooks are unavailable.
 *
 * Interim fallback: gateway bank-side polling still updates payment status;
 * this cron reads that status (and can nudge via operations/poll) then applies
 * the same order transitions as webhooks via {@see VEXPay_Gateway::apply_payment_result()}.
 *
 * Schedule: Action Scheduler (preferred) or WP-Cron every {@see VEXPay_Helpers::POLL_INTERVAL}
 * seconds (60). Per-order cooldown {@see VEXPay_Helpers::POLL_ORDER_COOLDOWN} (45s) can make
 * effective API attempts look ~1–2 minutes apart under light traffic / delayed cron wakes.
 *
 * @package VEXPay_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settlement poller (Action Scheduler preferred, WP-Cron fallback).
 */
class VEXPay_Poller {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedules' ) );
		add_action( VEXPay_Helpers::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 20 );
	}

	/**
	 * Custom 1-minute schedule for native WP-Cron.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function register_cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules[ VEXPay_Helpers::CRON_SCHEDULE ] ) ) {
			$schedules[ VEXPay_Helpers::CRON_SCHEDULE ] = array(
				'interval' => VEXPay_Helpers::POLL_INTERVAL,
				'display'  => __( 'Every minute (VEXPay settlement poll)', 'woo-vexpay-gateway' ),
			);
		}
		return $schedules;
	}

	/**
	 * Schedule on plugin activate.
	 */
	public static function activate(): void {
		// Ensure the custom interval exists before wp_schedule_event (init hooks are not loaded yet).
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedules' ) );
		self::maybe_schedule();
	}

	/**
	 * Clear schedules on plugin deactivate.
	 */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( VEXPay_Helpers::CRON_HOOK );
		}
		wp_clear_scheduled_hook( VEXPay_Helpers::CRON_HOOK );
	}

	/**
	 * Ensure a recurring job exists (self-heal if activate was skipped).
	 */
	public static function maybe_schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( VEXPay_Helpers::CRON_HOOK ) ) {
				as_schedule_recurring_action(
					time() + VEXPay_Helpers::POLL_INTERVAL,
					VEXPay_Helpers::POLL_INTERVAL,
					VEXPay_Helpers::CRON_HOOK,
					array(),
					'vexpay'
				);
			}
			// Prefer Action Scheduler; clear any legacy WP-Cron duplicate.
			wp_clear_scheduled_hook( VEXPay_Helpers::CRON_HOOK );
			return;
		}

		if ( ! wp_next_scheduled( VEXPay_Helpers::CRON_HOOK ) ) {
			wp_schedule_event( time() + VEXPay_Helpers::POLL_INTERVAL, VEXPay_Helpers::CRON_SCHEDULE, VEXPay_Helpers::CRON_HOOK );
		}
	}

	/**
	 * Cron callback: find pending VEXPay orders and reconcile via API lookup.
	 */
	public static function run(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return;
		}

		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( empty( $gateways['vexpay'] ) || ! $gateways['vexpay'] instanceof VEXPay_Gateway ) {
			return;
		}

		/** @var VEXPay_Gateway $gateway */
		$gateway = $gateways['vexpay'];
		if ( 'yes' !== $gateway->enabled ) {
			return;
		}

		$client = $gateway->get_api_client();
		$orders = self::find_candidate_orders();
		$now    = time();
		$done   = 0;

		VEXPay_Logger::debug( sprintf( 'Settlement poll: %d candidate order(s).', count( $orders ) ) );

		foreach ( $orders as $order ) {
			if ( $done >= VEXPay_Helpers::POLL_BATCH_LIMIT ) {
				break;
			}

			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$order_id     = $order->get_id();
			$payment_id   = (string) $order->get_meta( VEXPay_Helpers::META_PAYMENT_ID );
			$external_ref = (string) $order->get_meta( VEXPay_Helpers::META_EXTERNAL_REF );
			$status       = (string) $order->get_meta( VEXPay_Helpers::META_STATUS );
			$last_polled  = (int) $order->get_meta( VEXPay_Helpers::META_LAST_POLLED_AT );
			$created      = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;

			$skip = VEXPay_Helpers::settlement_poll_eligibility_skip_reason(
				$order->get_payment_method(),
				$status,
				$payment_id,
				$order->is_paid()
			);
			if ( null !== $skip ) {
				VEXPay_Logger::debug(
					sprintf(
						'Settlement poll: skip order #%d — %s (externalRef=%s paymentId=%s status=%s).',
						$order_id,
						self::describe_skip_reason( $skip ),
						'' !== $external_ref ? $external_ref : '-',
						'' !== $payment_id ? $payment_id : '-',
						'' !== $status ? $status : '-'
					)
				);
				continue;
			}

			if ( VEXPay_Helpers::is_poll_expired( $created, $now ) ) {
				VEXPay_Logger::debug(
					sprintf(
						'Settlement poll: skip order #%d — expired (> %ds old) (externalRef=%s).',
						$order_id,
						VEXPay_Helpers::POLL_MAX_AGE,
						'' !== $external_ref ? $external_ref : '-'
					)
				);
				continue;
			}

			if ( VEXPay_Helpers::was_polled_recently( $last_polled, $now ) ) {
				$age = $last_polled > 0 ? ( $now - $last_polled ) : 0;
				VEXPay_Logger::debug(
					sprintf(
						'Settlement poll: skip order #%d — cooldown (%ds ago, need %ds) (externalRef=%s paymentId=%s).',
						$order_id,
						$age,
						VEXPay_Helpers::POLL_ORDER_COOLDOWN,
						'' !== $external_ref ? $external_ref : '-',
						'' !== $payment_id ? $payment_id : '-'
					)
				);
				continue;
			}

			$order->update_meta_data( VEXPay_Helpers::META_LAST_POLLED_AT, (string) $now );
			$order->save();

			$endpoint = self::preferred_lookup_endpoint( $payment_id, $external_ref );
			VEXPay_Logger::debug(
				sprintf(
					'Settlement poll: order #%d attempting %s (externalRef=%s paymentId=%s metaStatus=%s).',
					$order_id,
					$endpoint,
					'' !== $external_ref ? $external_ref : '-',
					'' !== $payment_id ? $payment_id : '-',
					'' !== $status ? $status : '-'
				)
			);

			$result = self::lookup_payment( $client, $payment_id, $external_ref );
			++$done;

			if ( is_wp_error( $result ) ) {
				VEXPay_Logger::debug(
					sprintf(
						'Settlement poll: order #%d lookup failed: %s',
						$order_id,
						$result->get_error_message()
					)
				);
				continue;
			}

			$receipt     = VEXPay_Helpers::normalize_payment_lookup_result( $result );
			$new_status  = isset( $receipt['status'] ) ? strtoupper( (string) $receipt['status'] ) : '';
			$prev_status = strtoupper( $status );
			$resolved_id = ! empty( $receipt['paymentId'] ) ? (string) $receipt['paymentId'] : $payment_id;

			if ( '' === $new_status ) {
				VEXPay_Logger::debug(
					sprintf(
						'Settlement poll: order #%d — empty status in lookup response (paymentId=%s).',
						$order_id,
						'' !== $resolved_id ? $resolved_id : '-'
					)
				);
				continue;
			}

			VEXPay_Logger::info(
				sprintf(
					'Settlement poll: order #%d externalRef=%s paymentId=%s %s → %s',
					$order_id,
					'' !== $external_ref ? $external_ref : '-',
					'' !== $resolved_id ? $resolved_id : '-',
					'' !== $prev_status ? $prev_status : '-',
					$new_status
				)
			);

			// Still pending — nothing to apply beyond meta timestamp.
			if ( VEXPay_Helpers::is_pending_settlement_status( $new_status ) && $prev_status === $new_status ) {
				VEXPay_Logger::debug(
					sprintf(
						'Settlement poll: order #%d still %s — waiting for bank / gateway settle.',
						$order_id,
						$new_status
					)
				);
				continue;
			}

			$gateway->apply_payment_result( $order, $receipt );
			VEXPay_Logger::info(
				sprintf(
					'Settlement poll: order #%d applied %s (paid=%s).',
					$order_id,
					$new_status,
					$order->is_paid() ? 'yes' : 'no'
				)
			);
		}
	}

	/**
	 * Human-readable skip reason for logs.
	 *
	 * @param string $reason Machine reason from helpers.
	 * @return string
	 */
	private static function describe_skip_reason( string $reason ): string {
		if ( 'missing_payment_id' === $reason ) {
			return 'missing_payment_id (awaiting OTP execute — no VEXPay payment row yet)';
		}
		if ( str_starts_with( $reason, 'status_not_pending:' ) ) {
			return $reason;
		}
		return $reason;
	}

	/**
	 * Which endpoint lookup will try first.
	 *
	 * @param string $payment_id   Payment UUID.
	 * @param string $external_ref External ref.
	 * @return string
	 */
	private static function preferred_lookup_endpoint( string $payment_id, string $external_ref ): string {
		if ( '' !== $payment_id ) {
			return 'POST /v1/payments/operations/poll';
		}
		if ( '' !== $external_ref ) {
			return 'GET /v1/payments/by-ref/' . $external_ref;
		}
		return 'none';
	}

	/**
	 * Query unpaid VEXPay orders that may need settlement.
	 *
	 * Broad WC query (on-hold/pending); eligibility filters + skip logs run per order.
	 *
	 * @return WC_Order[]
	 */
	private static function find_candidate_orders(): array {
		$orders = wc_get_orders(
			array(
				'limit'          => VEXPay_Helpers::POLL_BATCH_LIMIT * 2,
				'status'         => array( 'on-hold', 'pending' ),
				'payment_method' => 'vexpay',
				'orderby'        => 'date',
				'order'          => 'ASC',
				'return'         => 'objects',
			)
		);

		return is_array( $orders ) ? $orders : array();
	}

	/**
	 * Resolve current payment status from VEXPay.
	 *
	 * Prefers operations/poll (nudges bank consult) when payment id is known,
	 * then falls back to GET by-ref / GET by id.
	 *
	 * @param VEXPay_API_Client $client       API client.
	 * @param string            $payment_id   Payment UUID.
	 * @param string            $external_ref External ref.
	 * @return array|WP_Error
	 */
	private static function lookup_payment( VEXPay_API_Client $client, string $payment_id, string $external_ref ) {
		if ( '' !== $payment_id ) {
			$polled = $client->poll_payment_operation( 'debit', $payment_id );
			if ( ! is_wp_error( $polled ) && is_array( $polled ) ) {
				return $polled;
			}
			VEXPay_Logger::debug(
				sprintf(
					'Settlement poll: operations/poll failed for %s (%s); falling back to GET.',
					$payment_id,
					is_wp_error( $polled ) ? $polled->get_error_message() : 'invalid response'
				)
			);
		}

		if ( '' !== $external_ref ) {
			$by_ref = $client->get_payment_by_ref( $external_ref );
			if ( ! is_wp_error( $by_ref ) ) {
				return $by_ref;
			}
			VEXPay_Logger::debug(
				sprintf(
					'Settlement poll: by-ref %s failed (%s); trying GET by id.',
					$external_ref,
					$by_ref->get_error_message()
				)
			);
		}

		if ( '' !== $payment_id ) {
			return $client->get_payment( $payment_id );
		}

		return new WP_Error( 'vexpay_poll_missing_ids', __( 'Missing payment id and external ref for settlement poll.', 'woo-vexpay-gateway' ) );
	}
}
