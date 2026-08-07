# VEXPay Gateway for WooCommerce

WooCommerce payment gateway for **VEXPay** (Venezuela C2P). The plugin talks HTTP only (`x-api-key`) to the hosted VEXPay API — it does not embed bank SDKs and does not depend on the VEXPay API source repo.

## Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 8.1+
- A VEXPay merchant account with a **Test** (and later Live) API key from the [VEXPay dashboard](https://banking.chuventures.com)

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

The API origin is fixed to `https://api.banking.chuventures.com` (not editable in settings). Sandbox on/off switches which stored API key is active; the UI shows a single **API key** field (no Test/Live labels).

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
