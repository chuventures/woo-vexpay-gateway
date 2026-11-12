# VEXPay Gateway for WooCommerce

WooCommerce payment gateway for **VEXPay** (Venezuela C2P). The plugin talks HTTP only (`x-api-key`) to the hosted VEXPay API — it does not embed bank SDKs and does not depend on the VEXPay API source repo.

## Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 8.1+
- A VEXPay merchant account with a **Test** (and later Live) API key from the [VEXPay dashboard](https://app.vexpay.app)

## Install (development)

```bash
composer install
npm i -g @wordpress/env   # once
npx wp-env start
```

Default site: [http://localhost:8888](http://localhost:8888)

## Configure the gateway

**WooCommerce → Settings → Payments → VEXPay**

| Setting | Value |
| ------- | ----- |
| Enable | Yes |
| Sandbox | On (toggle) |
| API key | Sandbox key from VEXPay dashboard |

The API origin is fixed to `https://api.vexpay.app` (not editable in settings). Sandbox on/off switches which stored API key is active; the UI shows a single **API key** field (no Test/Live labels).

1. Click **Test connection** — expect bank count and BCV rate.
2. Save settings (webhook registration runs when the store URL is reachable from VEXPay).

### Webhooks on local wp-env

The hosted API must POST to `…/wc-api/vexpay_webhook`. Localhost is not reachable from VEXPay, so either:

- Expose wp-env with a tunnel (e.g. ngrok) and register that public URL in the VEXPay dashboard, or
- Skip webhook checks at first and verify the OTP happy path (browser redirect), then add webhooks once a public URL exists.

### Settlement without webhooks (interim poller)

When débito returns `AC00` (pending), the VEXPay gateway settles bank-side asynchronously. If webhooks are not reachable yet, the plugin schedules a recurring job (`vexpay_poll_pending_payments`, every 60s via Action Scheduler when WooCommerce provides it, otherwise WP-Cron) that:

1. Finds unpaid VEXPay orders (`on-hold` / `pending`) and skips those without `_vexpay_payment_id` or a pending `_vexpay_status` (pre-OTP orders stay on-hold until OTP execute)
2. Calls `POST /v1/payments/operations/poll` (fallback: `GET /v1/payments/by-ref/:externalRef` or `GET /v1/payments/:id`)
3. Applies the same `apply_payment_result` path as webhooks

Per-order cooldown is 45s, so with a 60s schedule you typically see about one API attempt per minute; under light traffic WP-Cron / Action Scheduler may wake every ~2 minutes — that is expected. Enable **Debug log** under gateway settings to see skip reasons, endpoint attempts, and status transitions in WooCommerce → Status → Logs. Keep webhook registration for production once the store URL is public.

## Débito inmediato flow

1. Checkout: cédula, phone, bank → quote + order `on-hold`
2. Customer opens OTP page → `POST /v1/payments/debit/otp`
3. OTP → `POST /v1/payments/debit` (`ACCP` paid, `AC00` pending)
4. Webhooks reconcile via `POST …/wc-api/vexpay_webhook` (HMAC `X-Webhook-Signature`), or the settlement poller when webhooks are unavailable

In Test mode, use the sandbox OTP from the VEXPay Portal (Test twin), not a real bank app.

## Marketplace

For marketplaces whose products belong to different comercios (sellers). With **Marketplace splits** on, one customer payment (one OTP, one card charge) is split between the comercios in the cart and you, the marketplace. Each comercio needs its own VEX Pay comercio (merchant) under your account. Marketplace mode is **off by default**; while it is off, nothing changes and no `splits` are sent.

**Turn it on:** WooCommerce → Settings → Payments → VEXPay → **Marketplace splits**.

**How shares are computed**

- Each product line belongs to at most one comercio. The comercio is resolved in this order: the `vexpay_line_item_merchant_id` filter, the variation's **Comercio (VEX Pay)** field, then the parent product's field. A line with no comercio belongs to the marketplace.
- A comercio's share is the sum of its lines' totals after coupons, plus their tax (`line_total + line_tax`), in the order currency (USD).
- Shipping, fees and lines with no comercio stay with the marketplace.
- Débito: shares are sent in VES, converted with the order's own quote and rounded **down** to 0.01 (`floor2(shareUsd × vesAmount / usdAmount)`). Card (VPOS): shares are sent in USD with `usdAmount`. Any rounding residue goes to the marketplace.
- At most 10 comercios per order. Shares never sum above the payment.
- The shares are stored on the order (`_vexpay_splits`) and shown in the **VEX Pay split** box on the order screen. When VEX Pay reports the payment (response or `payment.completed` webhook), the box also shows each comercio's commission, VEX Pay fee and net.
- USDT is hidden (classic and block checkout) for carts with comercio items, because USDT payments cannot be split.
- If VEX Pay refuses the split (for example an inactive or unknown comercio), the customer sees "Este plan no está disponible para pago en este momento", no money is taken, the order is marked failed with a note naming the comercios, and the error is logged. The payment is never retried without splits.

Your commission per comercio defaults to the comercio's `applicationFeePercent` in VEX Pay (then your account default). The comercio's share bears the VEX Pay fee: comercio net = share × (1 − VEX Pay rate − commission %).

**Mapping products:** the **Comercio (VEX Pay)** field on the product (General tab) and on each variation lists your active comercios from `GET /v1/merchants` (cached for 10 minutes; use **Refresh comercio list** after adding one).

### Custom comercio systems

If your store keeps its own comercio records, create the VEX Pay comercio when you save yours, and tell the plugin which comercio each item belongs to:

```php
// 1. Create or update the VEX Pay comercio when your comercio is saved.
add_action( 'my_comercio_saved', function ( $comercio ) {
	$merchant_id = vexpay_upsert_merchant( array(
		'external_ref'            => (string) $comercio->id,    // Your comercio id (idempotency key).
		'name'                    => $comercio->name,
		'identification'          => $comercio->rif,            // V/E + 8 digits, or J/G + 9 digits.
		'contact_email'           => $comercio->email,
		'contact_phone'           => $comercio->phone,
		'bank_code'               => $comercio->bank_code,      // Pago Móvil payout account, e.g. "0134".
		'phone'                   => $comercio->pago_movil_phone,
		'application_fee_percent' => 20,                        // Your commission on its shares.
		'auto_payout'             => true,
	) );
	if ( is_wp_error( $merchant_id ) ) {
		error_log( 'VEX Pay comercio sync failed: ' . $merchant_id->get_error_message() );
		return;
	}
	update_post_meta( $comercio->id, '_vexpay_merchant_id', $merchant_id );
} );

// 2. Map order lines (payment) and cart items (hides USDT) to the comercio.
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

// 3. Optional: change the final shares, e.g. a commission per comercio.
add_filter( 'vexpay_order_splits', function ( $splits, $order, $method ) {
	foreach ( $splits as &$share ) {
		$share['applicationFeePercent'] = 15; // Or applicationFeeVes.
	}
	return $splits;
}, 10, 3 );
```

`vexpay_upsert_merchant()` returns the VEX Pay merchant id or a `WP_Error`. Calling it again with the same `external_ref` updates the same comercio (commission, auto-payout, active flag). A new or changed Pago Móvil account is added as a new payout method and must be verified with the two micro-deposit amounts before the comercio can be paid out.

## Tests

### Unit

```bash
composer install
composer test
```

### Manual E2E

With wp-env running and a Test API key configured, follow [docs/E2E-CHECKLIST.md](docs/E2E-CHECKLIST.md).

Suggested order: **Test connection** → C2P + OTP → failures → webhooks (tunnel) → refunds → Blocks checkout.

## wordpress.org

See [docs/WORDPRESS-ORG.md](docs/WORDPRESS-ORG.md). Build a release zip:

```bash
git tag v1.0.0 && git push origin v1.0.0
# or
rsync -a --exclude-from=.distignore ./ /tmp/vexpay-gateway-for-woocommerce/
cd /tmp && zip -r vexpay-gateway-for-woocommerce.zip vexpay-gateway-for-woocommerce
```

## License

GPLv2 or later — see [LICENSE](LICENSE).
