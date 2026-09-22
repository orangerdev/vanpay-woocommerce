<?php
/**
 * Admin actions: register/rotate the Vanpay webhook endpoint, test connection.
 *
 * @package Vanpay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Vanpay webhook manager.
 */
class Vanpay_Webhook_Manager {

	const OPTION = 'vanpay_wc_webhook';

	/**
	 * Hook up the admin-post handler.
	 */
	public static function init() {
		add_action( 'admin_post_vanpay_wc_webhook', array( __CLASS__, 'handle_action' ) );
	}

	/**
	 * Handle the settings-screen buttons (test / register / rotate).
	 */
	public static function handle_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'vanpay-woocommerce' ) );
		}
		check_admin_referer( 'vanpay_wc_webhook' );

		$do     = sanitize_key( wp_unslash( $_GET['do'] ?? '' ) );
		$client = Vanpay_API_Client::from_settings();

		if ( ! $client ) {
			Vanpay_Admin::add_notice( 'error', __( 'Save your Vanpay API key first.', 'vanpay-woocommerce' ) );
			self::redirect_back();
		}

		switch ( $do ) {
			case 'test':
				self::test_connection( $client );
				break;
			case 'register':
				self::register_endpoint( $client );
				break;
			case 'rotate':
				self::rotate_secret( $client );
				break;
			default:
				Vanpay_Admin::add_notice( 'error', __( 'Unknown action.', 'vanpay-woocommerce' ) );
		}

		self::redirect_back();
	}

	/**
	 * Test the API key by reading merchant settings.
	 *
	 * @param Vanpay_API_Client $client Client.
	 */
	private static function test_connection( Vanpay_API_Client $client ) {
		$settings = $client->get_merchant_settings();

		if ( is_wp_error( $settings ) ) {
			Vanpay_Admin::add_notice(
				'error',
				sprintf(
					/* translators: 1: error code, 2: error message */
					__( 'Connection failed: %1$s — %2$s', 'vanpay-woocommerce' ),
					$settings->get_error_code(),
					$settings->get_error_message()
				)
			);
			return;
		}

		$onboarded = ! empty( $settings['onboarding_completed'] );
		Vanpay_Admin::add_notice(
			$onboarded ? 'success' : 'warning',
			sprintf(
				/* translators: 1: merchant display name, 2: default currency, 3: default fee mode, 4: onboarding status */
				__( 'Connected to Vanpay as "%1$s". Default currency: %2$s, fee mode: %3$s. Onboarding: %4$s.', 'vanpay-woocommerce' ),
				(string) ( $settings['display_name'] ?? '?' ),
				(string) ( $settings['default_currency'] ?? '?' ),
				(string) ( $settings['default_fee_mode'] ?? '?' ),
				$onboarded ? __( 'completed', 'vanpay-woocommerce' ) : __( 'NOT completed — payments cannot be created yet', 'vanpay-woocommerce' )
			)
		);
	}

	/**
	 * Register this site's receiver URL and store the signing secret.
	 *
	 * @param Vanpay_API_Client $client Client.
	 */
	private static function register_endpoint( Vanpay_API_Client $client ) {
		$url      = rest_url( 'vanpay/v1/webhook' );
		$response = $client->create_webhook_endpoint( $url );

		if ( is_wp_error( $response ) ) {
			Vanpay_Admin::add_notice(
				'error',
				sprintf(
					/* translators: 1: error code, 2: error message */
					__( 'Webhook registration failed: %1$s — %2$s', 'vanpay-woocommerce' ),
					$response->get_error_code(),
					$response->get_error_message()
				)
			);
			return;
		}

		$endpoint_id = (string) ( $response['id'] ?? '' );
		$secret      = (string) ( $response['signing_secret'] ?? '' );

		// Fall back to the reveal endpoint if the create response omitted the secret.
		if ( '' !== $endpoint_id && '' === $secret ) {
			$revealed = $client->reveal_webhook_secret( $endpoint_id );
			if ( ! is_wp_error( $revealed ) ) {
				$secret = (string) ( $revealed['secret'] ?? '' );
			}
		}

		if ( '' === $endpoint_id || '' === $secret ) {
			Vanpay_Admin::add_notice( 'error', __( 'Webhook registered but the signing secret could not be retrieved. Try "Rotate secret".', 'vanpay-woocommerce' ) );
		}

		update_option(
			self::OPTION,
			array(
				'endpoint_id' => $endpoint_id,
				'secret'      => $secret,
				'url'         => (string) ( $response['url'] ?? $url ),
			),
			false // No autoload: only needed on webhook/admin requests.
		);

		if ( '' !== $endpoint_id && '' !== $secret ) {
			Vanpay_Admin::add_notice( 'success', __( 'Webhook registered with Vanpay and signing secret stored.', 'vanpay-woocommerce' ) );
		}

		vanpay_wc_log( sprintf( 'Webhook endpoint registered: %s', $endpoint_id ), 'info' );
	}

	/**
	 * Rotate the signing secret and store the new one.
	 *
	 * @param Vanpay_API_Client $client Client.
	 */
	private static function rotate_secret( Vanpay_API_Client $client ) {
		$webhook     = get_option( self::OPTION, array() );
		$endpoint_id = (string) ( $webhook['endpoint_id'] ?? '' );

		if ( '' === $endpoint_id ) {
			Vanpay_Admin::add_notice( 'error', __( 'No registered webhook endpoint to rotate.', 'vanpay-woocommerce' ) );
			return;
		}

		$response = $client->rotate_webhook_secret( $endpoint_id );

		if ( is_wp_error( $response ) || empty( $response['secret'] ) ) {
			Vanpay_Admin::add_notice(
				'error',
				is_wp_error( $response )
					? sprintf( '%s — %s', $response->get_error_code(), $response->get_error_message() )
					: __( 'Secret rotation failed: no secret in the response.', 'vanpay-woocommerce' )
			);
			return;
		}

		$webhook['secret'] = (string) $response['secret'];
		update_option( self::OPTION, $webhook, false );

		Vanpay_Admin::add_notice( 'success', __( 'Signing secret rotated and stored.', 'vanpay-woocommerce' ) );
		vanpay_wc_log( sprintf( 'Webhook signing secret rotated for endpoint %s.', $endpoint_id ), 'info' );
	}

	/**
	 * Redirect back to the gateway settings screen and exit.
	 */
	private static function redirect_back() {
		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=vanpay' ) );
		exit;
	}
}
