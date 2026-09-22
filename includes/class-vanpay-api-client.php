<?php
/**
 * Thin HTTP client for the Vanpay API (https://api.vanpay.io).
 *
 * @package Vanpay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Vanpay API client.
 */
class Vanpay_API_Client {

	const BASE_URL = 'https://api.vanpay.io';

	/**
	 * Merchant API key (vpk_live_...).
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Build a client from the saved gateway settings.
	 *
	 * @return self|null Null when no API key is configured.
	 */
	public static function from_settings() {
		$settings = get_option( 'woocommerce_vanpay_settings', array() );
		$api_key  = trim( (string) ( $settings['api_key'] ?? '' ) );
		return '' !== $api_key ? new self( $api_key ) : null;
	}

	/**
	 * Constructor.
	 *
	 * @param string $api_key Merchant API key.
	 */
	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Create a Payment.
	 *
	 * @param array  $body            Payment body (amount, customer_email, ...).
	 * @param string $idempotency_key Idempotency key (required by Vanpay).
	 * @return array|WP_Error
	 */
	public function create_payment( array $body, string $idempotency_key ) {
		return $this->request( 'POST', '/v1/payments', $body, array( 'idempotency-key' => $idempotency_key ) );
	}

	/**
	 * Get a Payment by UUID.
	 *
	 * @param string $id Payment UUID.
	 * @return array|WP_Error
	 */
	public function get_payment( string $id ) {
		return $this->request( 'GET', '/v1/payments/' . rawurlencode( $id ) );
	}

	/**
	 * Disable a Payment (blocks checkout access; a disabled payment can still become paid).
	 *
	 * @param string $id Payment UUID.
	 * @return array|WP_Error
	 */
	public function disable_payment( string $id ) {
		return $this->request( 'POST', '/v1/payments/' . rawurlencode( $id ) . '/disable' );
	}

	/**
	 * Get merchant settings (onboarding status, defaults, payout wallet).
	 *
	 * @return array|WP_Error
	 */
	public function get_merchant_settings() {
		return $this->request( 'GET', '/v1/merchant/settings' );
	}

	/**
	 * List on-ramp providers.
	 *
	 * @return array|WP_Error
	 */
	public function get_providers() {
		return $this->request( 'GET', '/v1/providers' );
	}

	/**
	 * Register a webhook endpoint for payment.paid events.
	 *
	 * @param string $url Receiver URL.
	 * @return array|WP_Error
	 */
	public function create_webhook_endpoint( string $url ) {
		return $this->request(
			'POST',
			'/v1/webhook-endpoints',
			array(
				'url'         => $url,
				'description' => 'WooCommerce - ' . home_url(),
				'events'      => array( 'payment.paid' ),
			)
		);
	}

	/**
	 * Get a webhook endpoint.
	 *
	 * @param string $id Endpoint UUID.
	 * @return array|WP_Error
	 */
	public function get_webhook_endpoint( string $id ) {
		return $this->request( 'GET', '/v1/webhook-endpoints/' . rawurlencode( $id ) );
	}

	/**
	 * Reveal the current signing secret of a webhook endpoint.
	 *
	 * @param string $id Endpoint UUID.
	 * @return array|WP_Error
	 */
	public function reveal_webhook_secret( string $id ) {
		return $this->request( 'POST', '/v1/webhook-endpoints/' . rawurlencode( $id ) . '/secret' );
	}

	/**
	 * Rotate the signing secret of a webhook endpoint.
	 *
	 * @param string $id Endpoint UUID.
	 * @return array|WP_Error
	 */
	public function rotate_webhook_secret( string $id ) {
		return $this->request( 'POST', '/v1/webhook-endpoints/' . rawurlencode( $id ) . '/secret/rotate' );
	}

	/**
	 * Perform an authenticated request against the Vanpay API.
	 *
	 * @param string     $method        HTTP method.
	 * @param string     $path          Path beginning with /v1/.
	 * @param array|null $body          JSON body or null.
	 * @param array      $extra_headers Additional headers.
	 * @return array|WP_Error Decoded JSON on 2xx, WP_Error otherwise.
	 */
	private function request( string $method, string $path, $body = null, array $extra_headers = array() ) {
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array_merge(
				array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Accept'        => 'application/json',
				),
				$extra_headers
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::BASE_URL . $path, $args );

		if ( is_wp_error( $response ) ) {
			vanpay_wc_log( sprintf( 'HTTP error on %s %s: %s', $method, $path, $response->get_error_message() ), 'error' );
			return $response;
		}

		$status     = (int) wp_remote_retrieve_response_code( $response );
		$request_id = (string) wp_remote_retrieve_header( $response, 'x-request-id' );
		$data       = json_decode( wp_remote_retrieve_body( $response ), true );

		vanpay_wc_log( sprintf( '%s %s -> %d (request_id: %s)', $method, $path, $status, $request_id ), 'debug' );

		if ( $status >= 200 && $status < 300 ) {
			return is_array( $data ) ? $data : array();
		}

		$error_code    = (string) ( $data['error']['code'] ?? 'http_' . $status );
		$error_message = (string) ( $data['error']['message'] ?? __( 'Vanpay API request failed.', 'vanpay-woocommerce' ) );
		$retry_after   = (int) wp_remote_retrieve_header( $response, 'retry-after' );

		vanpay_wc_log(
			sprintf( '%s %s failed: %d %s - %s (request_id: %s)', $method, $path, $status, $error_code, $error_message, $request_id ),
			'error'
		);

		return new WP_Error(
			$error_code,
			$error_message,
			array(
				'status'      => $status,
				'request_id'  => $request_id,
				'retry_after' => $retry_after,
			)
		);
	}
}
