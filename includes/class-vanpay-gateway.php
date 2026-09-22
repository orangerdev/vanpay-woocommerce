<?php
/**
 * Vanpay payment gateway.
 *
 * @package Vanpay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Vanpay gateway: redirects the customer to the Vanpay hosted checkout.
 */
class Vanpay_Gateway extends WC_Payment_Gateway {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'vanpay';
		$this->has_fields         = false;
		$this->method_title       = __( 'Vanpay', 'vanpay-woocommerce' );
		$this->method_description = __( 'Accept payments through the Vanpay hosted crypto checkout. The customer pays at checkout.vanpay.io and the order is confirmed by a signed webhook plus an authenticated API read. Note: Vanpay checkout does not redirect the customer back to the store.', 'vanpay-woocommerce' );
		// No refunds: the Vanpay API has no refund capability.
		$this->supports = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// The Vanpay checkout never redirects back and on-hold orders cannot use
		// the order-pay page, so surface the checkout link on the thank-you page
		// and in the on-hold customer email as the way back into payment.
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'           => array(
				'title'   => __( 'Enable/Disable', 'vanpay-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Vanpay', 'vanpay-woocommerce' ),
				'default' => 'no',
			),
			'title'             => array(
				'title'       => __( 'Title', 'vanpay-woocommerce' ),
				'type'        => 'safe_text',
				'description' => __( 'Payment method title shown at checkout.', 'vanpay-woocommerce' ),
				'default'     => __( 'Crypto payment (Vanpay)', 'vanpay-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'       => array(
				'title'       => __( 'Description', 'vanpay-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description shown at checkout.', 'vanpay-woocommerce' ),
				'default'     => __( 'You will be redirected to Vanpay to complete your payment. Keep the confirmation email — your order is updated automatically once the payment is confirmed.', 'vanpay-woocommerce' ),
				'desc_tip'    => true,
			),
			'api_key'           => array(
				'title'       => __( 'API key', 'vanpay-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Your live merchant API key (vpk_live_...). Vanpay has no test keys — all payments are live.', 'vanpay-woocommerce' ),
				'default'     => '',
			),
			'provider'          => array(
				'title'       => __( 'On-ramp provider', 'vanpay-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Pin a specific on-ramp provider, or let the customer choose one at the Vanpay checkout. Confirm the provider supports your store currency.', 'vanpay-woocommerce' ),
				'default'     => '',
				'options'     => $this->get_provider_options(),
				'desc_tip'    => true,
			),
			'fee_mode'          => array(
				'title'       => __( 'Fee mode', 'vanpay-woocommerce' ),
				'type'        => 'select',
				'description' => __( '"Merchant" deducts Vanpay fees from your payout; "Customer" adds them to the checkout amount. "Merchant default" uses the setting saved in your Vanpay dashboard.', 'vanpay-woocommerce' ),
				'default'     => '',
				'options'     => array(
					''         => __( 'Merchant default', 'vanpay-woocommerce' ),
					'merchant' => __( 'Merchant pays the fees', 'vanpay-woocommerce' ),
					'customer' => __( 'Customer pays the fees', 'vanpay-woocommerce' ),
				),
				'desc_tip'    => true,
			),
			'paid_order_status' => array(
				'title'       => __( 'Order status after payment', 'vanpay-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Status applied when a Vanpay payment is confirmed. "WooCommerce default" lets WooCommerce decide (processing, or completed for virtual/downloadable orders).', 'vanpay-woocommerce' ),
				'default'     => 'default',
				'options'     => array(
					'default'    => __( 'WooCommerce default', 'vanpay-woocommerce' ),
					'processing' => __( 'Processing', 'vanpay-woocommerce' ),
					'completed'  => __( 'Completed', 'vanpay-woocommerce' ),
				),
				'desc_tip'    => true,
			),
			'auto_cancel_hours' => array(
				'title'             => __( 'Auto-cancel unpaid orders after (hours)', 'vanpay-woocommerce' ),
				'type'              => 'number',
				'description'       => __( 'Vanpay payments never expire, so unpaid on-hold orders stay open forever unless cleaned up. Set 0 to disable. When enabled, the Vanpay payment is also disabled so its checkout link stops working.', 'vanpay-woocommerce' ),
				'default'           => '0',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			),
			'debug'             => array(
				'title'       => __( 'Debug log', 'vanpay-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log Vanpay API requests (WooCommerce &rarr; Status &rarr; Logs, source "vanpay"). Keys and secrets are never logged.', 'vanpay-woocommerce' ),
				'default'     => 'no',
			),
			'webhook_info'      => array(
				'title' => __( 'Webhook', 'vanpay-woocommerce' ),
				'type'  => 'webhook_info',
			),
		);
	}

	/**
	 * Render the webhook status/actions row on the settings screen.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 * @return string
	 */
	public function generate_webhook_info_html( $key, $data ) {
		$webhook_url = rest_url( 'vanpay/v1/webhook' );
		$webhook     = get_option( 'vanpay_wc_webhook', array() );
		$registered  = ! empty( $webhook['endpoint_id'] ) && ! empty( $webhook['secret'] );

		$action_url = function ( $do ) {
			return wp_nonce_url(
				admin_url( 'admin-post.php?action=vanpay_wc_webhook&do=' . $do ),
				'vanpay_wc_webhook'
			);
		};

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<p>
					<?php esc_html_e( 'Receiver URL:', 'vanpay-woocommerce' ); ?>
					<code><?php echo esc_html( $webhook_url ); ?></code>
				</p>
				<p>
					<?php if ( $registered ) : ?>
						<span style="color:#008a20;">&#10004; <?php esc_html_e( 'Webhook registered with Vanpay — signing secret stored.', 'vanpay-woocommerce' ); ?></span>
						<br /><small><?php echo esc_html( sprintf( /* translators: %s: endpoint UUID */ __( 'Endpoint ID: %s', 'vanpay-woocommerce' ), $webhook['endpoint_id'] ) ); ?></small>
					<?php else : ?>
						<span style="color:#d63638;">&#10008; <?php esc_html_e( 'Webhook not registered yet. Save your API key, then click "Register webhook".', 'vanpay-woocommerce' ); ?></span>
					<?php endif; ?>
				</p>
				<p>
					<a class="button" href="<?php echo esc_url( $action_url( 'test' ) ); ?>"><?php esc_html_e( 'Test connection', 'vanpay-woocommerce' ); ?></a>
					<a class="button" href="<?php echo esc_url( $action_url( 'register' ) ); ?>"><?php esc_html_e( 'Register webhook', 'vanpay-woocommerce' ); ?></a>
					<?php if ( $registered ) : ?>
						<a class="button" href="<?php echo esc_url( $action_url( 'rotate' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Rotate the signing secret? The old secret stops working immediately.', 'vanpay-woocommerce' ) ); ?>');"><?php esc_html_e( 'Rotate secret', 'vanpay-woocommerce' ); ?></a>
					<?php endif; ?>
				</p>
				<p class="description">
					<?php esc_html_e( 'The webhook must be reachable from the internet over HTTPS. On a local development site webhooks cannot arrive — the reconciliation job (every 15 minutes) confirms payments instead.', 'vanpay-woocommerce' ); ?>
				</p>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Provider dropdown options (fetched only on this gateway's settings screen, cached 1 hour).
	 *
	 * @return array
	 */
	private function get_provider_options() {
		$options = array( '' => __( 'Let the customer choose at Vanpay checkout', 'vanpay-woocommerce' ) );

		// Keep the current value selectable even when the list is unavailable.
		$settings = get_option( 'woocommerce_vanpay_settings', array() );
		$current  = (string) ( $settings['provider'] ?? '' );
		if ( '' !== $current ) {
			$options[ $current ] = $current;
		}

		// Only call the API while rendering this gateway's own settings screen.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		if ( ! is_admin() || 'vanpay' !== ( $_GET['section'] ?? '' ) ) {
			return $options;
		}

		foreach ( self::get_cached_providers() as $provider ) {
			if ( 'active' !== ( $provider['status'] ?? '' ) || empty( $provider['id'] ) ) {
				continue;
			}
			$options[ $provider['id'] ] = sprintf(
				'%s (min %s %s)',
				$provider['name'] ?? $provider['id'],
				$provider['minimum_amount'] ?? '?',
				$provider['minimum_currency'] ?? ''
			);
		}

		return $options;
	}

	/**
	 * Providers list, cached in a transient.
	 *
	 * @return array
	 */
	public static function get_cached_providers() {
		$providers = get_transient( 'vanpay_wc_providers' );
		if ( false !== $providers ) {
			return (array) $providers;
		}

		$providers = array();
		$client    = Vanpay_API_Client::from_settings();
		if ( $client ) {
			$response = $client->get_providers();
			if ( ! is_wp_error( $response ) ) {
				$providers = (array) ( $response['providers'] ?? array() );
			}
		}

		set_transient( 'vanpay_wc_providers', $providers, HOUR_IN_SECONDS );
		return $providers;
	}

	/**
	 * Refresh cached data after saving settings.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		delete_transient( 'vanpay_wc_providers' );
		return parent::process_admin_options();
	}

	/**
	 * Availability at checkout.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		if ( '' === trim( (string) $this->get_option( 'api_key' ) ) ) {
			return false;
		}

		// Vanpay rejects 0.00 amounts.
		$total = $this->get_order_total();
		if ( $total <= 0 ) {
			return false;
		}

		// Respect a pinned provider's minimum when the currencies match.
		$provider_id = (string) $this->get_option( 'provider' );
		if ( '' !== $provider_id ) {
			foreach ( self::get_cached_providers() as $provider ) {
				if ( ( $provider['id'] ?? '' ) !== $provider_id ) {
					continue;
				}
				if (
					get_woocommerce_currency() === ( $provider['minimum_currency'] ?? '' ) &&
					$total < (float) ( $provider['minimum_amount'] ?? 0 )
				) {
					return false;
				}
				break;
			}
		}

		return true;
	}

	/**
	 * Create the Vanpay payment and redirect to its hosted checkout.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'vanpay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$body = array(
			'amount'         => number_format( (float) $order->get_total(), 2, '.', '' ),
			'customer_email' => $order->get_billing_email(),
			'currency'       => $order->get_currency(),
			'reference'      => (string) $order->get_id(),
		);

		$provider = (string) $this->get_option( 'provider' );
		if ( '' !== $provider ) {
			$body['provider'] = $provider;
		}

		$fee_mode = (string) $this->get_option( 'fee_mode' );
		if ( in_array( $fee_mode, array( 'merchant', 'customer' ), true ) ) {
			$body['fee_mode'] = $fee_mode;
		}

		$request_hash = md5( (string) wp_json_encode( $body ) );
		$stored_hash  = (string) $order->get_meta( '_vanpay_request_hash' );
		$checkout_url = (string) $order->get_meta( '_vanpay_checkout_url' );

		// Same order, unchanged details, payment already created: reuse it.
		if ( '' !== $checkout_url && $stored_hash === $request_hash ) {
			return array(
				'result'   => 'success',
				'redirect' => $checkout_url,
			);
		}

		// Vanpay ties an idempotency key to the exact body forever. Bump the
		// attempt counter whenever the request body changes so a modified
		// order gets a fresh key instead of a 409 idempotency_conflict.
		$attempt = (int) $order->get_meta( '_vanpay_attempt' );
		if ( 0 === $attempt ) {
			$attempt = 1;
		} elseif ( '' !== $stored_hash && $stored_hash !== $request_hash ) {
			++$attempt;
		}

		$idempotency_key = sprintf(
			'wc_%s_order_%d_attempt_%d',
			substr( md5( home_url() ), 0, 8 ),
			$order->get_id(),
			$attempt
		);

		$client  = new Vanpay_API_Client( (string) $this->get_option( 'api_key' ) );
		$payment = $client->create_payment( $body, $idempotency_key );

		if ( is_wp_error( $payment ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: error code, 2: error message, 3: request id */
					__( 'Vanpay payment creation failed: %1$s — %2$s (request_id: %3$s)', 'vanpay-woocommerce' ),
					$payment->get_error_code(),
					$payment->get_error_message(),
					(string) ( $payment->get_error_data()['request_id'] ?? '-' )
				)
			);
			$order->save();
			wc_add_notice(
				__( 'We could not start the Vanpay payment. Please try again or choose another payment method.', 'vanpay-woocommerce' ),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		$payment_id   = (string) ( $payment['id'] ?? '' );
		$checkout_url = (string) ( $payment['checkout_url'] ?? '' );

		if ( '' === $payment_id || '' === $checkout_url ) {
			vanpay_wc_log( sprintf( 'Order #%d: create payment response missing id/checkout_url.', $order->get_id() ), 'error' );
			wc_add_notice( __( 'Unexpected response from Vanpay. Please try again.', 'vanpay-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_vanpay_payment_id', $payment_id );
		$order->update_meta_data( '_vanpay_checkout_url', $checkout_url );
		$order->update_meta_data( '_vanpay_checkout_amount', (string) ( $payment['checkout_amount'] ?? '' ) );
		$order->update_meta_data( '_vanpay_request_hash', $request_hash );
		$order->update_meta_data( '_vanpay_attempt', $attempt );

		$order->update_status(
			'on-hold',
			sprintf(
				/* translators: %s: payment UUID */
				__( 'Awaiting Vanpay payment (payment ID: %s).', 'vanpay-woocommerce' ),
				$payment_id
			)
		);
		$order->save();

		if ( isset( WC()->cart ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $checkout_url,
		);
	}

	/**
	 * Thank-you page: offer the checkout link while the order awaits payment.
	 *
	 * @param int $order_id Order ID.
	 */
	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->has_status( 'on-hold' ) ) {
			return;
		}

		$checkout_url = (string) $order->get_meta( '_vanpay_checkout_url' );
		if ( '' === $checkout_url ) {
			return;
		}

		echo '<section class="vanpay-pay-now" style="margin:1.5em 0;">';
		echo '<p>' . esc_html__( 'Your order is awaiting payment. If you have not completed it yet, or the payment window was closed, you can continue here:', 'vanpay-woocommerce' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( $checkout_url ) . '">' . esc_html__( 'Complete your payment', 'vanpay-woocommerce' ) . '</a></p>';
		echo '<p>' . esc_html__( 'Your order is updated automatically once the payment is confirmed.', 'vanpay-woocommerce' ) . '</p>';
		echo '</section>';
	}

	/**
	 * Add the checkout link to the customer's on-hold email.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Whether the email goes to the admin.
	 * @param bool     $plain_text    Whether the email is plain text.
	 */
	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $sent_to_admin || ! $order instanceof WC_Order ) {
			return;
		}
		if ( $this->id !== $order->get_payment_method() || ! $order->has_status( 'on-hold' ) ) {
			return;
		}

		$checkout_url = (string) $order->get_meta( '_vanpay_checkout_url' );
		if ( '' === $checkout_url ) {
			return;
		}

		if ( $plain_text ) {
			echo esc_html__( 'Complete your payment:', 'vanpay-woocommerce' ) . ' ' . esc_url( $checkout_url ) . "\n\n";
			return;
		}

		echo '<p>' . esc_html__( 'If you have not completed your payment yet, you can continue here:', 'vanpay-woocommerce' ) . ' ';
		echo '<a href="' . esc_url( $checkout_url ) . '">' . esc_html__( 'Complete your payment', 'vanpay-woocommerce' ) . '</a></p>';
	}
}

