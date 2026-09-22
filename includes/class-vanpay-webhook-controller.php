<?php
/**
 * REST controller receiving Vanpay payment.paid webhooks.
 *
 * Implements Standard Webhooks verification: HMAC-SHA256 over
 * "{webhook-id}.{webhook-timestamp}.{raw body}", base64-encoded,
 * with a whsec_-prefixed base64 secret and a ±5 minute tolerance.
 *
 * @package Vanpay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Vanpay webhook controller.
 */
class Vanpay_Webhook_Controller {

	const TIMESTAMP_TOLERANCE = 300; // Seconds.

	/**
	 * Register the REST route. Authentication is the webhook signature.
	 */
	public function register_routes() {
		register_rest_route(
			'vanpay/v1',
			'/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	
		// Lightweight status endpoint polled by the thank-you waiting panel.
		// Authorized by the WooCommerce order key (the same capability token
		// that grants access to the order-received page). No PII is returned.
		register_rest_route(
			'vanpay/v1',
			'/order-status/(?P<order_id>\\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'order_status' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'order_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'key'      => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Order payment status for the waiting panel.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function order_status( WP_REST_Request $request ) {
		$order = wc_get_order( absint( $request['order_id'] ) );
		$key   = (string) $request['key'];

		if (
			! $order instanceof WC_Order ||
			'vanpay' !== $order->get_payment_method() ||
			'' === $key ||
			! hash_equals( $order->get_order_key(), $key )
		) {
			return new WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
		}

		$response = new WP_REST_Response(
			array(
				'paid'   => $order->is_paid(),
				'status' => $order->get_status(),
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Handle a webhook delivery.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ) {
		$raw_body  = (string) $request->get_body();
		$msg_id    = (string) $request->get_header( 'webhook-id' );
		$timestamp = (string) $request->get_header( 'webhook-timestamp' );
		$signature = (string) $request->get_header( 'webhook-signature' );

		if ( '' === $msg_id || '' === $timestamp || '' === $signature ) {
			return new WP_REST_Response( array( 'error' => 'missing_headers' ), 400 );
		}

		if ( abs( time() - (int) $timestamp ) > self::TIMESTAMP_TOLERANCE ) {
			vanpay_wc_log( 'Webhook rejected: timestamp outside tolerance.', 'warning' );
			return new WP_REST_Response( array( 'error' => 'stale_timestamp' ), 400 );
		}

		$webhook = get_option( 'vanpay_wc_webhook', array() );
		$secret  = (string) ( $webhook['secret'] ?? '' );
		if ( '' === $secret ) {
			// Not configured yet: 500 so Vanpay retries after the merchant fixes setup.
			vanpay_wc_log( 'Webhook received but no signing secret is stored.', 'error' );
			return new WP_REST_Response( array( 'error' => 'not_configured' ), 500 );
		}

		// Verify the signature over the exact raw bytes BEFORE parsing.
		if ( ! $this->verify_signature( $msg_id, $timestamp, $raw_body, $signature, $secret ) ) {
			vanpay_wc_log( 'Webhook rejected: invalid signature.', 'warning' );
			return new WP_REST_Response( array( 'error' => 'invalid_signature' ), 401 );
		}

		$event = json_decode( $raw_body, true );
		if ( ! is_array( $event ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_payload' ), 400 );
		}

		if ( 'payment.paid' !== ( $event['type'] ?? '' ) || '1' !== (string) ( $event['version'] ?? '' ) ) {
			// Unknown event type/version: acknowledge and ignore.
			return new WP_REST_Response( array( 'ignored' => true ), 200 );
		}

		$event_id = (string) ( $event['id'] ?? '' );
		$payment  = is_array( $event['data']['payment'] ?? null ) ? $event['data']['payment'] : array();

		$order = $this->resolve_order( $payment );
		if ( ! $order ) {
			// The payment may belong to another integration on the same
			// merchant account — acknowledge so Vanpay does not retry.
			vanpay_wc_log(
				sprintf( 'Webhook: no matching order for payment %s (reference: %s).', $payment['id'] ?? '?', $payment['reference'] ?? '?' ),
				'warning'
			);
			return new WP_REST_Response( array( 'ignored' => true ), 200 );
		}

		// Dedupe on the event envelope id (stable across retries and replays).
		$processed = (array) $order->get_meta( '_vanpay_processed_events' );
		if ( '' !== $event_id && in_array( $event_id, $processed, true ) ) {
			return new WP_REST_Response( array( 'duplicate' => true ), 200 );
		}

		// Launch-checklist requirement: confirm via an authenticated read,
		// never from the webhook body alone.
		$client = Vanpay_API_Client::from_settings();
		if ( ! $client ) {
			return new WP_REST_Response( array( 'error' => 'not_configured' ), 500 );
		}

		$fresh = $client->get_payment( (string) $payment['id'] );
		if ( is_wp_error( $fresh ) ) {
			// Temporary failure: 500 so Vanpay retries the delivery.
			return new WP_REST_Response( array( 'error' => 'verification_failed' ), 500 );
		}

		$result = Vanpay_Order_Sync::process_paid_payment( $order, $fresh, 'webhook' );

		if ( is_wp_error( $result ) ) {
			if ( 'vanpay_not_paid' === $result->get_error_code() ) {
				// The API does not confirm the paid status yet — let Vanpay retry.
				return new WP_REST_Response( array( 'error' => 'not_paid' ), 500 );
			}
			// Amount mismatch etc.: logged and noted on the order; acknowledge
			// so the delivery is not retried forever.
			return new WP_REST_Response( array( 'received' => true ), 200 );
		}

		if ( '' !== $event_id ) {
			$processed[] = $event_id;
			$order->update_meta_data( '_vanpay_processed_events', $processed );
			$order->save();
		}

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Resolve the WooCommerce order for a webhook payment payload.
	 *
	 * @param array $payment Payment from the event payload.
	 * @return WC_Order|null
	 */
	private function resolve_order( array $payment ) {
		$payment_id = (string) ( $payment['id'] ?? '' );
		$reference  = (string) ( $payment['reference'] ?? '' );

		if ( '' === $payment_id || ! ctype_digit( $reference ) ) {
			return null;
		}

		$order = wc_get_order( (int) $reference );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		// The order must be ours and reference this exact payment.
		if ( 'vanpay' !== $order->get_payment_method() ) {
			return null;
		}
		if ( (string) $order->get_meta( '_vanpay_payment_id' ) !== $payment_id ) {
			return null;
		}

		return $order;
	}

	/**
	 * Standard Webhooks signature check.
	 *
	 * @param string $msg_id    webhook-id header.
	 * @param string $timestamp webhook-timestamp header.
	 * @param string $body      Raw request body.
	 * @param string $header    webhook-signature header (space-separated "v1,<base64>" entries).
	 * @param string $secret    Signing secret (whsec_<base64>).
	 * @return bool
	 */
	private function verify_signature( string $msg_id, string $timestamp, string $body, string $header, string $secret ) {
		if ( 0 === strpos( $secret, 'whsec_' ) ) {
			$secret = substr( $secret, 6 );
		}

		$key = base64_decode( $secret, true );
		if ( false === $key || '' === $key ) {
			return false;
		}

		$expected = base64_encode( hash_hmac( 'sha256', $msg_id . '.' . $timestamp . '.' . $body, $key, true ) );

		foreach ( explode( ' ', trim( $header ) ) as $part ) {
			$pieces = explode( ',', $part, 2 );
			if ( 2 === count( $pieces ) && 'v1' === $pieces[0] && hash_equals( $expected, $pieces[1] ) ) {
				return true;
			}
		}

		return false;
	}
}
