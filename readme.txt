=== Vanpay for WooCommerce ===
Contributors: ridwanarifandi
Tags: woocommerce, payment gateway, crypto, vanpay
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept payments through the Vanpay hosted crypto checkout.

== Description ==

This plugin adds Vanpay (https://vanpay.io) as a WooCommerce payment gateway.

* The customer is redirected to the Vanpay hosted checkout (checkout.vanpay.io) to pay.
* Orders are placed on-hold while awaiting payment and confirmed by a signed
  `payment.paid` webhook (Standard Webhooks HMAC-SHA256) PLUS an authenticated
  re-read of the payment from the Vanpay API before fulfilment.
* A reconciliation job (Action Scheduler, every 15 minutes) polls Vanpay for
  on-hold orders as a safety net when webhooks are delayed or unreachable.
* Idempotency keys are managed automatically so retried checkouts reuse the
  same Vanpay payment instead of creating duplicates.
* Optional: pin an on-ramp provider, choose the fee mode, choose the order
  status applied after payment (WooCommerce default / processing / completed),
  and auto-cancel orders that stay unpaid for N hours.
* HPOS (custom order tables) compatible.

Important notes:

* Vanpay has NO test/sandbox keys — every payment is live.
* Vanpay checkout does not redirect the customer back to the store; the order
  is confirmed asynchronously.
* The Vanpay API has no refund capability, so the gateway does not offer refunds.
* On a local development site webhooks cannot arrive from the internet; the
  reconciliation job (or the "Check payment status" button on the order screen)
  confirms payments instead.

== Installation ==

1. Activate the plugin.
2. Go to WooCommerce -> Settings -> Payments -> Vanpay.
3. Paste your live merchant API key (vpk_live_...), save, then click
   "Test connection" and "Register webhook".

== Changelog ==

= 1.0.0 =
* Initial release.
