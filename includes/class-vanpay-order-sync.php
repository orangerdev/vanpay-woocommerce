<?php
/**
 * Shared "mark order paid" logic used by the webhook controller,
 * the reconciliation job, and the manual admin action.
 *
 * @package Vanpay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Vanpay order sync.
 */
class Vanpay_Order_Sync {

	/**
	 * Validate an authenticated Payment read against the order and complete it.
	 *
	 * The $payment array MUST come from an authenticated GET /v1/payments/{id}
	 * read (or have been re-verified against one) — never straight from an
	 * unverified webhook body.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $payment Payment object from the Vanpay API.
	 * @param string   $context Short label for order notes/logs (webhook|reconciliation|manual).
	 * @return true|WP_Error True when the order is (now) paid.
	 */
	public static function process_paid_payment( WC_Order $order, array $payment, string $context ) {
		$payment_id = (string) ( $payment['id'] ?? '' );

		if ( 'paid' !== ( $payment['status'] ?? '' ) ) {
			return new WP_Error( 'vanpay_not_paid', __( 'The Vanpay payment is not paid yet.', 'vanpay-woocommerce' ) );
		}

		if ( $order->is_paid() ) {
			return true;
		}

		// The Face Amount and currency must match the order exactly.
		$expected_amount = number_format( (float) $order->get_total(), 2, '.', '' );
		$paid_amount     = (string) ( $payment['amount'] ?? '' );
		$paid_currency   = (string) ( $payment['currency'] ?? '' );

		if ( $paid_amount !== $expected_amount || $paid_currency !== $order->get_currency() ) {
			$message = sprintf(
				/* translators: 1: paid amount, 2: paid currency, 3: expected amount, 4: expected currency */
				__( 'Vanpay payment amount mismatch: paid %1$s %2$s but the order expects %3$s %4$s. The order was NOT completed automatically — review it manually.', 'vanpay-woocommerce' ),
				$paid_amount,
				$paid_currency,
				$expected_amount,
				$order->get_currency()
			);
			$order->add_order_note( $message );
			$order->save();
			vanpay_wc_log( sprintf( 'Order #%d (%s): %s', $order->get_id(), $context, $message ), 'error' );
			return new WP_Error( 'vanpay_amount_mismatch', $message );
		}

		// Persist settlement details before completing the order.
		$settlement = is_array( $payment['settlement'] ?? null ) ? $payment['settlement'] : array();
		$order->update_meta_data( '_vanpay_settlement', $settlement );
		$order->update_meta_data( '_vanpay_paid_at', (string) ( $payment['paid_at'] ?? '' ) );

		$order->payment_complete( $payment_id );

		$order->add_order_note(
			sprintf(
				/* translators: 1: payment UUID, 2: context, 3: crypto asset, 4: amount received, 5: incoming tx id */
				__( 'Vanpay payment %1$s confirmed (via %2$s). Settlement: %3$s %4$s, incoming tx: %5$s.', 'vanpay-woocommerce' ),
				$payment_id,
				$context,
				(string) ( $settlement['amount_received'] ?? '?' ),
				(string) ( $settlement['crypto_name'] ?? '?' ),
				(string) ( $settlement['incoming_transaction_id'] ?? '?' )
			)
		);

		// Apply the configured post-payment order status, if any.
		$settings = get_option( 'woocommerce_vanpay_settings', array() );
		$target   = (string) ( $settings['paid_order_status'] ?? 'default' );
		if ( in_array( $target, array( 'processing', 'completed' ), true ) && ! $order->has_status( $target ) ) {
			$order->update_status(
				$target,
				__( 'Vanpay: applying the configured paid order status.', 'vanpay-woocommerce' )
			);
		}

		$order->save();

		vanpay_wc_log( sprintf( 'Order #%d marked paid via %s (payment %s).', $order->get_id(), $context, $payment_id ), 'info' );

		return true;
	}
}
