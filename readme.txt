=== VEXPay Gateway for WooCommerce ===
Contributors: tinhochu
Tags: woocommerce, payments, venezuela, c2p, pago movil
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Venezuela C2P payments in WooCommerce via the VEXPay payment gateway.

== Description ==

VEXPay Gateway for WooCommerce lets Venezuelan shoppers pay with **C2P** (bank OTP / token) through [VEXPay](https://pay.vexwallet.co).

= Features =

* C2P checkout: cédula/RIF, phone, bank, then OTP confirmation
* **Test connection** button — verify API key and reachability from the shop
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
4. Turn on **Test mode** and paste your **Test** API key from the VEXPay dashboard.
5. Click **Test connection** (banks + BCV rate), enable the gateway, then save.
6. Place a test order. In Test mode, use the sandbox OTP from the VEXPay Portal — not a real bank app.
7. For webhooks, your store must be reachable by VEXPay (`…/wc-api/vexpay_webhook`). Local sites need a public URL or tunnel.

Developer docs and E2E checklist: https://github.com/chuventures/woo-vexpay-gateway

== Installation ==

1. Upload the `vexpay-gateway-for-woocommerce` folder to `/wp-content/plugins/`, or install the zip via **Plugins → Add New**.
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

== External services ==

This plugin connects to the VEXPay API (https://api.pay.vexwallet.co), a paid Venezuelan payment-processing service, to authorize, confirm (OTP), and refund C2P/Débito Inmediato payments. It is required for the plugin's core function: without it, no payments can be processed.

The following data is sent to VEXPay:

* When a customer checks out: order amount and currency, and the debtor's payer details entered at checkout (cédula/RIF, phone number, and issuing bank) — needed to initiate the C2P charge.
* When a customer confirms payment: the OTP/token they received from their own banking app.
* When a merchant clicks "Test connection" in the gateway settings: the store's configured VEXPay API key, to verify it is valid and reachable.
* When an order is refunded: the original payment reference, to request a reverse.
* VEXPay also calls back to this site's webhook endpoint with signed payment-status updates.

No data is sent unless a customer actively submits the checkout/OTP form, a merchant explicitly tests the connection, or a refund is triggered by store staff.

VEXPay Terms of Service: https://vexwallet.co/cumplimiento
VEXPay Privacy Policy: https://vexwallet.co/politica-privacidad

This plugin also loads the "IBM Plex Sans" and "Outfit" web fonts from Google Fonts (fonts.googleapis.com / fonts.gstatic.com) on the checkout and OTP confirmation pages, for consistent typography. Loading these fonts causes the visitor's browser to make a direct request to Google, which transmits their IP address to Google.
Google Fonts: [Google Fonts Terms of Service](https://developers.google.com/fonts/faq), [Google Privacy Policy](https://policies.google.com/privacy)

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
