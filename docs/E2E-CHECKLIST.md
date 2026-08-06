# Local E2E checklist (wp-env + VEXPay)

Prerequisites: `npx wp-env start` in this repo; `pnpm dev` in `ve-banking` (API on `:3010`).

## Setup

- [ ] Activate WooCommerce + VEXPay Gateway
- [ ] WooCommerce → Settings → Payments → VEXPay → Enable
- [ ] API base URL: `http://host.docker.internal:3010` (Docker) or `http://localhost:3010`
- [ ] Test mode ON; Test API key: `dev-local-api-key-test`
- [ ] Save settings; confirm WooCommerce log or settings show webhook endpoint ID / secret filled
- [ ] If webhook URL is not reachable from the API host, register manually in VEXPay dashboard pointing at `http://…/wc-api/vexpay_webhook` (or use a tunnel such as ngrok)

## Happy path

- [ ] Add a product priced in USD; checkout with VEXPay
- [ ] Enter cédula `V12345678`, phone `04121234567`, pick a bank
- [ ] Order becomes `on-hold`; redirect to OTP / order-pay
- [ ] Enter sandbox OTP from VEXPay Portal Sandbox (Test twin)
- [ ] Order becomes paid (`processing` / `completed`); meta `_vexpay_payment_id` set

## Failures

- [ ] Wrong OTP → notice + order stays unpaid / failed note
- [ ] Invalid cédula at checkout → validation error

## Webhooks

- [ ] With a reachable webhook URL, complete a payment and confirm `payment.completed` updates the order if the browser never submitted OTP success redirect
- [ ] Tampered `X-Webhook-Signature` → 401

## Refunds

- [ ] Full refund on a COMPLETED C2P order → VEXPay reverse → order refunded
- [ ] Partial refund amount → error (unsupported in v1)

## Blocks

- [ ] Cart & Checkout Blocks: VEXPay fields render; order completes through OTP step
