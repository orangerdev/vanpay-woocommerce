<?php
/**
 * Plugin Name: Vanpay for WooCommerce
 * Plugin URI: https://vanpay.io
 * Description: Accept payments through the Vanpay hosted crypto checkout. Orders are confirmed via signed webhooks and authenticated API reads.
 * Version: 1.0.0
 * Author: Ridwan Arifandi
 * Author URI: https://ridwan-arifandi.com/cle
 * Text Domain: vanpay-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 10.1
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'VANPAY_WC_VERSION', '1.0.0' );
define( 'VANPAY_WC_PLUGIN_FILE', __FILE__ );
define( 'VANPAY_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Log a message to the WooCommerce logger (source: vanpay).
 *
 * Debug-level messages are only written when the gateway's Debug log
 * setting is enabled. Never pass API keys or signing secrets in here.
 *
 * @param string $message Message.
 * @param string $level   One of debug|info|notice|warning|error|critical.
 * @param array  $context Extra context.
 */
function vanpay_wc_log( string $message, string $level = 'info', array $context = array() ) {
	if ( 'debug' === $level ) {
		$settings = get_option( 'woocommerce_vanpay_settings', array() );
		if ( 'yes' !== ( $settings['debug'] ?? 'no' ) ) {
			return;
		}
	}
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->log( $level, $message, array_merge( array( 'source' => 'vanpay' ), $context ) );
	}
}

// Declare HPOS (custom order tables) compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Bootstrap once WooCommerce is loaded.
 */
function vanpay_wc_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	require_once VANPAY_WC_PLUGIN_DIR . 'includes/class-vanpay-api-client.php';
	require_once VANPAY_WC_PLUGIN_DIR . 'includes/class-vanpay-order-sync.php';
	require_once VANPAY_WC_PLUGIN_DIR . 'includes/class-vanpay-gateway.php';
	require_once VANPAY_WC_PLUGIN_DIR . 'includes/class-vanpay-webhook-controller.php';
	require_once VANPAY_WC_PLUGIN_DIR . 'includes/class-vanpay-webhook-manager.php';
	require_once VANPAY_WC_PLUGIN_DIR . 'includes/class-vanpay-reconciliation.php';
	require_once VANPAY_WC_PLUGIN_DIR . 'includes/class-vanpay-admin.php';

	Vanpay_Webhook_Manager::init();
	Vanpay_Reconciliation::init();
	Vanpay_Admin::init();
}
add_action( 'plugins_loaded', 'vanpay_wc_init' );

// Register the gateway.
add_filter(
	'woocommerce_payment_gateways',
	function ( $gateways ) {
		if ( class_exists( 'Vanpay_Gateway' ) ) {
			$gateways[] = 'Vanpay_Gateway';
		}
		return $gateways;
	}
);

// Webhook REST route.
add_action(
	'rest_api_init',
	function () {
		if ( class_exists( 'Vanpay_Webhook_Controller' ) ) {
			( new Vanpay_Webhook_Controller() )->register_routes();
		}
	}
);

// Settings link on the Plugins screen.
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=vanpay' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'vanpay-woocommerce' ) . '</a>' );
		return $links;
	}
);

// Clean up the reconciliation schedule on deactivation.
register_deactivation_hook(
	__FILE__,
	function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'vanpay_wc_reconcile' );
		}
	}
);
