=== VEXPay Gateway for WooCommerce ===
Contributors: tinhochu
Tags: woocommerce, payments, venezuela, c2p, pago movil
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Venezuela C2P and card (VPOS) payments in WooCommerce via the VEXPay payment gateway.

== Description ==

VEXPay Gateway for WooCommerce lets Venezuelan shoppers pay with **C2P** (bank OTP / token) or a **Visa/Mastercard/Maestro card** through [VEXPay](https://vexpay.app/?utm_source=wp-repo&utm_medium=link&utm_campaign=readme).

= Features =

* C2P checkout: cédula/RIF, phone, bank, then OTP confirmation
* Card checkout (VPOS): a second, independent payment method that shares the same API key
* USDT checkout: redirects to VEXPay's hosted USDT page (shown only when USDT is enabled on your VEXPay account)
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

= Marketplace =

For marketplaces whose products belong to different comercios (sellers). Turn on **Marketplace splits** under **WooCommerce → Settings → Payments → VEXPay** (off by default; while off, nothing changes). One customer payment (one OTP, one card charge) is then split between the comercios in the cart and you.

* Map each product (or variation) to a VEX Pay comercio with the **Comercio (VEX Pay)** field, which lists your active comercios (cached 10 minutes, with a refresh link).
* A comercio's share is its lines' totals after coupons plus tax. Shipping, fees and unmapped items stay with the marketplace. Débito shares are converted to VES with the order's quote and rounded down to 0.01; card shares are sent in USD. At most 10 comercios per order.
* The **VEX Pay split** box on the order screen shows each comercio's share and, once VEX Pay reports the payment, its commission, VEX Pay fee and net.
* USDT is hidden for carts with comercio items (USDT payments cannot be split).
* If VEX Pay refuses the split (e.g. an inactive comercio), the customer sees "Este plan no está disponible para pago en este momento", no money is taken, and the order gets a note. The payment is never retried without splits.

Custom comercio systems can create comercios with `vexpay_upsert_merchant()` and map items with filters instead of the product field:

    add_action( 'my_comercio_saved', function ( $comercio ) {
        $merchant_id = vexpay_upsert_merchant( array(
            'external_ref'            => (string) $comercio->id, // your comercio id
            'name'                    => $comercio->name,
            'identification'          => $comercio->rif,         // V/E+8 or J/G+9 digits
            'contact_email'           => $comercio->email,
            'contact_phone'           => $comercio->phone,
            'bank_code'               => $comercio->bank_code,   // Pago Móvil payout account
            'phone'                   => $comercio->pago_movil_phone,
            'application_fee_percent' => 20,                     // your commission
            'auto_payout'             => true,
        ) );
        if ( ! is_wp_error( $merchant_id ) ) {
            update_post_meta( $comercio->id, '_vexpay_merchant_id', $merchant_id );
        }
    } );

    function my_vexpay_merchant_for_product( $product_id ) {
        $comercio_id = get_post_meta( $product_id, '_my_comercio_id', true );
        return $comercio_id ? (string) get_post_meta( $comercio_id, '_vexpay_merchant_id', true ) : '';
    }
    add_filter( 'vexpay_line_item_merchant_id', function ( $merchant_id, $item, $order ) {
        return my_vexpay_merchant_for_product( $item->get_product_id() ) ?: $merchant_id;
    }, 10, 3 );
    add_filter( 'vexpay_cart_item_merchant_id', function ( $merchant_id, $cart_item, $product ) {
        return my_vexpay_merchant_for_product( $cart_item['product_id'] ) ?: $merchant_id;
    }, 10, 3 );

The `vexpay_order_splits( $splits, $order, $method )` filter can change the final shares, for example to set `applicationFeePercent` per comercio. A new or changed Pago Móvil payout account must be verified with the two micro-deposit amounts before the comercio is paid out.

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

This plugin connects to the VEXPay API (https://api.vexpay.app), a paid Venezuelan payment-processing service, to authorize, confirm (OTP), and refund C2P/Débito Inmediato payments. It is required for the plugin's core function: without it, no payments can be processed.

The following data is sent to VEXPay:

* When a customer checks out: order amount and currency, and the debtor's payer details entered at checkout (cédula/RIF, phone number, and issuing bank) — needed to initiate the C2P charge.
* When a customer confirms payment: the OTP/token they received from their own banking app.
* When a merchant clicks "Test connection" in the gateway settings: the store's configured VEXPay API key, to verify it is valid and reachable.
* When an order is refunded: the original payment reference, to request a reverse.
* Marketplace splits only (off by default): the comercio (VEX Pay merchant) id and share amount of each comercio in the order, sent with the payment; the list of your comercios is read when staff edit a product; and `vexpay_upsert_merchant()` sends the comercio details your store passes to it (name, cédula/RIF, contact email and phone, Pago Móvil bank and phone, commission).
* VEXPay also calls back to this site's webhook endpoint with signed payment-status updates.

No data is sent unless a customer actively submits the checkout/OTP form, a merchant explicitly tests the connection, or a refund is triggered by store staff.

VEXPay Terms of Service: https://docs.vexpay.app/es/terms?utm_source=wp-repo&utm_medium=link&utm_campaign=readme-legal
VEXPay Privacy Policy: https://docs.vexpay.app/es/privacy?utm_source=wp-repo&utm_medium=link&utm_campaign=readme-legal

This plugin also loads the "IBM Plex Sans" and "Outfit" web fonts from Google Fonts (fonts.googleapis.com / fonts.gstatic.com) on the checkout and OTP confirmation pages, for consistent typography. Loading these fonts causes the visitor's browser to make a direct request to Google, which transmits their IP address to Google.
Google Fonts: [Google Fonts Terms of Service](https://developers.google.com/fonts/faq), [Google Privacy Policy](https://policies.google.com/privacy)

== Screenshots ==

1. Gateway settings (API keys and test mode)
2. Checkout C2P fields
3. OTP confirmation step

== Changelog ==

= 1.2.2 =
* Tweak: The plugin talks to VEX Pay at its new address, https://api.vexpay.app. Links to VEX Pay, its terms and its privacy policy point at vexpay.app.

= 1.2.1 =
* Tweak: Tested up to WordPress 7.1 and WooCommerce 11.2.

= 1.2.0 =
* New: VEXPay — USDT, a third payment gateway that redirects the buyer to VEXPay's hosted USDT checkout (deposit address on the network they choose). Shown only when USDT is enabled on your VEXPay account; sales settle to your VEXPay USDT balance. Orders complete from the `payment.completed` webhook; an underpaid transfer puts the order on hold instead. Shares the API key and Sandbox toggle of VEXPay Débito inmediato. Classic checkout and Cart & Checkout Blocks support.

= 1.1.0 =
* New: VEXPay — Tarjeta, a second payment gateway for Visa/Mastercard/Maestro charges via VEXPay VPOS. Shares the API key and Sandbox toggle already configured for VEXPay Débito inmediato. Classic checkout and Cart & Checkout Blocks support.

= 1.0.0 =
* Initial release: C2P intent + OTP, webhooks, refunds, Blocks support.

== Upgrade Notice ==

= 1.2.2 =
VEX Pay moved to vexpay.app. Nothing to configure: your API keys and webhooks keep working.

= 1.2.1 =
Compatibility update for WordPress 7.1 and WooCommerce 11.2.

= 1.2.0 =
Adds a USDT payment option (VEXPay hosted checkout), shown when USDT is enabled on your VEXPay account.

= 1.1.0 =
Adds a card (VPOS) payment option alongside Débito inmediato.

= 1.0.0 =
Initial public release.
