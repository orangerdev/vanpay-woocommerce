<?php
/**
 * Reconciliation job: polls Vanpay for on-hold orders in case webhooks
 * are delayed, lost, or unreachable (e.g. local development).
 *
 * @package Vanpay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Vanpay reconciliation.
 */
class Vanpay_Reconciliation {

	const HOOK       = 'vanpay_wc_reconcile';
	const BATCH_SIZE = 25;

	/**
	 * Hook up scheduling and the runner.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Ensure the recurring action exists (Action Scheduler ships with WooCommerce).
	 */
	public static function maybe_schedule() {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::HOOK ) ) {
			as_schedule_recurring_action( time() + 5 * MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS, self::HOOK, array(), 'vanpay' );
		}
	}

	/**
	 * Poll Vanpay for a batch of on-hold Vanpay orders.
	 */
	public static function run() {
		$client = Vanpay_API_Client::from_settings();
		if ( ! $client ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'status'         => array( 'on-hold' ),
				'payment_method' => 'vanpay',
				'limit'          => self::BATCH_SIZE,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);

		if ( empty( $orders ) ) {
			return;
		}

		$settings     = get_option( 'woocommerce_vanpay_settings', array() );
		$cancel_hours = (int) ( $settings['auto_cancel_hours'] ?? 0 );

		foreach ( $orders as $order ) {
			$payment_id = (string) $order->get_meta( '_vanpay_payment_id' );
			if ( '' === $payment_id ) {
				continue;
			}

			$payment = $client->get_payment( $payment_id );

			if ( is_wp_error( $payment ) ) {
				// Stop the batch on rate limiting; the next run picks it up.
				if ( 'rate_limit_exceeded' === $payment->get_error_code() ) {
					vanpay_wc_log( 'Reconciliation paused: rate limit reached.', 'warning' );
					break;
				}
				continue;
			}

			if ( 'paid' === ( $payment['status'] ?? '' ) ) {
				Vanpay_Order_Sync::process_paid_payment( $order, $payment, 'reconciliation' );
				continue;
			}

			// Optional stale-order cleanup: Vanpay payments never expire on
			// their own, so long-unpaid orders must be closed from our side.
			if ( $cancel_hours > 0 ) {
				$created = $order->get_date_created();
				if ( $created && ( time() - $created->getTimestamp() ) > $cancel_hours * HOUR_IN_SECONDS ) {
					// Best effort: block the checkout link first. A disabled
					// payment can still become paid if a confirmation was
					// already in flight — the payment stays linked on the
					// order for manual review in that case.
					$client->disable_payment( $payment_id );
					$order->update_status(
						'cancelled',
						sprintf(
							/* translators: %d: number of hours */
							__( 'Vanpay: unpaid for more than %d hours — checkout access disabled and order cancelled. If a payment confirmation still arrives, review the order manually.', 'vanpay-woocommerce' ),
							$cancel_hours
						)
					);
				}
			}
		}
	}
}
