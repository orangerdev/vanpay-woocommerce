<?php
/**
 * Admin: order meta box, manual status check, protected meta, notices.
 *
 * @package Vanpay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Vanpay admin.
 */
class Vanpay_Admin {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ), 10, 2 );
		add_filter( 'is_protected_meta', array( __CLASS__, 'protect_meta' ), 10, 2 );
		add_action( 'admin_post_vanpay_wc_check_status', array( __CLASS__, 'handle_check_status' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
	}

	/**
	 * Queue an admin notice for the current user (shown after redirect).
	 *
	 * @param string $type    success|warning|error|info.
	 * @param string $message Message text.
	 */
	public static function add_notice( string $type, string $message ) {
		$notices   = (array) get_transient( 'vanpay_wc_notices_' . get_current_user_id() );
		$notices[] = array(
			'type'    => $type,
			'message' => $message,
		);
		set_transient( 'vanpay_wc_notices_' . get_current_user_id(), $notices, MINUTE_IN_SECONDS );
	}

	/**
	 * Render queued notices.
	 */
	public static function render_notices() {
		$key     = 'vanpay_wc_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		if ( empty( $notices ) || ! is_array( $notices ) ) {
			return;
		}
		delete_transient( $key );

		foreach ( $notices as $notice ) {
			if ( empty( $notice['message'] ) ) {
				continue;
			}
			$type = in_array( $notice['type'] ?? '', array( 'success', 'warning', 'error', 'info' ), true ) ? $notice['type'] : 'info';
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p><strong>Vanpay:</strong> %2$s</p></div>',
				esc_attr( $type ),
				esc_html( $notice['message'] )
			);
		}
	}

	/**
	 * Register the order meta box on both HPOS and legacy order screens.
	 *
	 * @param string $screen_id Screen ID.
	 * @param mixed  $object    WP_Post or WC_Order.
	 */
	public static function add_meta_box( $screen_id, $object = null ) {
		$order_screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		if ( $screen_id !== $order_screen ) {
			return;
		}

		$order = self::resolve_order( $object );
		if ( ! $order || 'vanpay' !== $order->get_payment_method() ) {
			return;
		}

		add_meta_box(
			'vanpay-payment',
			__( 'Vanpay payment', 'vanpay-woocommerce' ),
			array( __CLASS__, 'render_meta_box' ),
			$order_screen,
			'side',
			'default'
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param mixed $object WP_Post or WC_Order.
	 */
	public static function render_meta_box( $object ) {
		$order = self::resolve_order( $object );
		if ( ! $order ) {
			return;
		}

		$payment_id      = (string) $order->get_meta( '_vanpay_payment_id' );
		$checkout_url    = (string) $order->get_meta( '_vanpay_checkout_url' );
		$checkout_amount = (string) $order->get_meta( '_vanpay_checkout_amount' );
		$paid_at         = (string) $order->get_meta( '_vanpay_paid_at' );
		$settlement      = (array) $order->get_meta( '_vanpay_settlement' );

		if ( '' === $payment_id ) {
			echo '<p>' . esc_html__( 'No Vanpay payment has been created for this order yet.', 'vanpay-woocommerce' ) . '</p>';
			return;
		}

		echo '<p><strong>' . esc_html__( 'Payment ID', 'vanpay-woocommerce' ) . ':</strong><br /><code style="word-break:break-all;">' . esc_html( $payment_id ) . '</code></p>';

		if ( '' !== $checkout_amount ) {
			echo '<p><strong>' . esc_html__( 'Checkout amount', 'vanpay-woocommerce' ) . ':</strong> ' . esc_html( $checkout_amount . ' ' . $order->get_currency() ) . '</p>';
		}

		if ( '' !== $checkout_url ) {
			echo '<p><a href="' . esc_url( $checkout_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open checkout link', 'vanpay-woocommerce' ) . ' &#8599;</a></p>';
		}

		if ( '' !== $paid_at ) {
			echo '<p><strong>' . esc_html__( 'Paid at (UTC)', 'vanpay-woocommerce' ) . ':</strong> ' . esc_html( $paid_at ) . '</p>';
		}

		if ( ! empty( $settlement ) ) {
			echo '<p><strong>' . esc_html__( 'Settlement', 'vanpay-woocommerce' ) . '</strong></p><ul style="margin-top:0;">';
			$labels = array(
				'crypto_name'             => __( 'Asset', 'vanpay-woocommerce' ),
				'amount_received'         => __( 'Received', 'vanpay-woocommerce' ),
				'amount_forwarded'        => __( 'Forwarded', 'vanpay-woocommerce' ),
				'incoming_transaction_id' => __( 'Incoming tx', 'vanpay-woocommerce' ),
				'outgoing_transaction_id' => __( 'Outgoing tx', 'vanpay-woocommerce' ),
			);
			foreach ( $labels as $field => $label ) {
				if ( ! empty( $settlement[ $field ] ) ) {
					echo '<li><strong>' . esc_html( $label ) . ':</strong> <span style="word-break:break-all;">' . esc_html( (string) $settlement[ $field ] ) . '</span></li>';
				}
			}
			echo '</ul>';
		}

		if ( ! $order->is_paid() ) {
			$check_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=vanpay_wc_check_status&order_id=' . $order->get_id() ),
				'vanpay_wc_check_status_' . $order->get_id()
			);
			echo '<p><a class="button" href="' . esc_url( $check_url ) . '">' . esc_html__( 'Check payment status', 'vanpay-woocommerce' ) . '</a></p>';
		}
	}

	/**
	 * Manual "Check payment status" action.
	 */
	public static function handle_check_status() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'vanpay-woocommerce' ) );
		}

		$order_id = absint( wp_unslash( $_GET['order_id'] ?? 0 ) );
		check_admin_referer( 'vanpay_wc_check_status_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'vanpay-woocommerce' ) );
		}

		$payment_id = (string) $order->get_meta( '_vanpay_payment_id' );
		$client     = Vanpay_API_Client::from_settings();

		if ( '' === $payment_id || ! $client ) {
			self::add_notice( 'error', __( 'No Vanpay payment on this order, or the API key is missing.', 'vanpay-woocommerce' ) );
		} else {
			$payment = $client->get_payment( $payment_id );

			if ( is_wp_error( $payment ) ) {
				self::add_notice(
					'error',
					sprintf(
						/* translators: 1: error code, 2: error message */
						__( 'Status check failed: %1$s — %2$s', 'vanpay-woocommerce' ),
						$payment->get_error_code(),
						$payment->get_error_message()
					)
				);
			} elseif ( 'paid' === ( $payment['status'] ?? '' ) ) {
				$result = Vanpay_Order_Sync::process_paid_payment( $order, $payment, 'manual' );
				if ( is_wp_error( $result ) ) {
					self::add_notice( 'error', $result->get_error_message() );
				} else {
					self::add_notice( 'success', __( 'Payment is paid — the order has been updated.', 'vanpay-woocommerce' ) );
				}
			} else {
				self::add_notice(
					'info',
					sprintf(
						/* translators: %s: payment status */
						__( 'Payment status at Vanpay: %s. The order was left unchanged.', 'vanpay-woocommerce' ),
						(string) ( $payment['status'] ?? 'unknown' )
					)
				);
			}
		}

		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	/**
	 * Hide Vanpay meta from the custom fields editor.
	 *
	 * @param bool   $protected Is protected.
	 * @param string $meta_key  Meta key.
	 * @return bool
	 */
	public static function protect_meta( $protected, $meta_key ) {
		if ( is_string( $meta_key ) && 0 === strpos( $meta_key, '_vanpay_' ) ) {
			return true;
		}
		return $protected;
	}

	/**
	 * Normalize the meta box object (WP_Post on legacy, WC_Order on HPOS).
	 *
	 * @param mixed $object Object.
	 * @return WC_Order|null
	 */
	private static function resolve_order( $object ) {
		if ( $object instanceof WC_Order ) {
			return $object;
		}
		if ( $object instanceof WP_Post ) {
			$order = wc_get_order( $object->ID );
			return $order instanceof WC_Order ? $order : null;
		}
		return null;
	}
}
