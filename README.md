# VEXPay Gateway for WooCommerce

WooCommerce payment gateway plugin for **VEXPay** (Venezuela C2P). Separate from the [`ve-banking`](https://github.com/chuventures/ve-banking) NestJS monorepo — this plugin speaks HTTP only (`x-api-key`).

## Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 8.1+
- VEXPay API (local `:3010` or `https://api.banking.chuventures.com`)

## Install (dev)

```bash
# From this repo
composer install
npm i -g @wordpress/env   # once
npx wp-env start

# In a second terminal, run VEXPay API
cd ../ve-banking && pnpm setup && pnpm dev
```

In WooCommerce → Settings → Payments → VEXPay:

| Setting | Local value |
|---------|-------------|
| API base URL | `http://host.docker.internal:3010` (wp-env) or `http://localhost:3010` |
| Test mode | Yes |
| Test API key | `dev-local-api-key-test` |

## C2P flow

1. Checkout: cédula, phone, bank → `POST /v1/payments/c2p/request`
2. Order `on-hold`; customer opens order-pay OTP form
3. OTP → `POST /v1/payments/c2p`
4. Webhooks reconcile via `POST …/wc-api/vexpay_webhook` (HMAC `X-Webhook-Signature`)

## Tests

```bash
composer install
composer test
```

Manual E2E checklist: [docs/E2E-CHECKLIST.md](docs/E2E-CHECKLIST.md)

## wordpress.org

See [docs/WORDPRESS-ORG.md](docs/WORDPRESS-ORG.md). Build a release zip:

```bash
git tag v1.0.0 && git push origin v1.0.0
# or
rsync -a --exclude-from=.distignore ./ /tmp/woo-vexpay-gateway/
cd /tmp && zip -r woo-vexpay-gateway.zip woo-vexpay-gateway
```

## License

GPLv2 or later — see [LICENSE](LICENSE).
