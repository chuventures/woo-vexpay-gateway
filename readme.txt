=== VEXPay Gateway for WooCommerce ===
Contributors: vexpay
Tags: woocommerce, payments, venezuela, c2p, pago movil
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Venezuela C2P payments in WooCommerce via the VEXPay payment gateway.

== Description ==

VEXPay Gateway for WooCommerce lets Venezuelan shoppers pay with **C2P** (bank OTP / token) through [VEXPay](https://banking.chuventures.com).

= Features =

* C2P checkout: cédula/RIF, phone, bank, then OTP confirmation
* Live and Test API keys (VEXPay twin environments)
* Signed webhooks for payment status reconciliation
* Full C2P reverse mapped to WooCommerce refunds
* Classic checkout and Cart & Checkout Blocks support
* Optional BCV VES amount display (settlement uses VEXPay BCV rate)

= Requirements =

* WordPress 6.0+
* WooCommerce 8.0+
* PHP 8.1+
* A VEXPay merchant account and API key

You configure your own API keys from the VEXPay dashboard. This plugin does not include paid locks or trial restrictions; VEXPay is a paid payment service (serviceware).

= Getting started =

1. Install and activate WooCommerce.
2. Install and activate this plugin.
3. Go to **WooCommerce → Settings → Payments → VEXPay**.
4. Enter your API base URL, Live and/or Test API keys, and enable the gateway.
5. Save settings (the plugin registers a webhook endpoint automatically when possible).
6. Place a test order with your Test API key.

Documentation: VEXPay tenant docs (C2P, webhooks, quote). Plugin source: https://github.com/chuventures/woo-vexpay-gateway

== Installation ==

1. Upload the `woo-vexpay-gateway` folder to `/wp-content/plugins/`, or install the zip via **Plugins → Add New**.
2. Activate the plugin.
3. Configure under **WooCommerce → Settings → Payments → VEXPay**.

== Frequently Asked Questions ==

= Where do I get an API key? =

From your VEXPay dashboard (Live and Test twins). Never put API keys in theme code or the browser.

= Does the plugin send SMS OTP? =

No. The customer obtains the OTP/token from their banking app (or sandbox OTP in Test mode). Your store collects the token and VEXPay executes the charge.

= Are partial refunds supported? =

v1 maps a WooCommerce refund to a full VEXPay C2P reverse of the original payment. Partial refunds are not supported.

= Which currencies are supported? =

Orders should be in USD (or your store currency converted to the USD amount sent to VEXPay). VEXPay settles in VES using the official BCV rate.

== Screenshots ==

1. Gateway settings (API keys and test mode)
2. Checkout C2P fields
3. OTP confirmation step

== Changelog ==

= 1.0.0 =
* Initial release: C2P intent + OTP, webhooks, refunds, Blocks support.

== Upgrade Notice ==

= 1.0.0 =
Initial public release.
