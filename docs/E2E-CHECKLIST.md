# E2E checklist (wp-env + hosted VEXPay)

This plugin is tested against the **hosted** VEXPay API. You do not need a local copy of the API codebase.

## Prerequisites

- [ ] `composer install` (optional: `composer test` for unit suite)
- [ ] `npx wp-env start` — site at `http://localhost:8888`
- [ ] VEXPay merchant account with a **Test** API key from the dashboard
- [ ] (Webhooks only) Public tunnel to the store, e.g. ngrok → wp-env

## Setup

- [ ] Activate WooCommerce + VEXPay Gateway
- [ ] **WooCommerce → Settings → Payments → VEXPay** → Enable
- [ ] Sandbox toggle **ON**; paste sandbox API key from dashboard
  (API origin is fixed in the plugin — not a settings field)
- [ ] **Test connection** → success with bank count + BCV rate
- [ ] Wrong key → unauthorized / clear error message
- [ ] Save settings
- [ ] If the store is publicly reachable (or tunneled), confirm webhook endpoint ID / secret is filled; otherwise register `https://<public-host>/wc-api/vexpay_webhook` in the VEXPay dashboard

## Happy path (OTP)

- [ ] Add a product priced in USD; checkout with VEXPay
- [ ] Enter cédula type `V` + number `12345678`, phone prefix `0412` + `1234567`, pick a bank
- [ ] Order becomes `on-hold`; redirect to order-pay **receipt** (step 2 — C2P code), not the “cannot be paid” error
- [ ] Enter sandbox C2P/OTP from VEXPay Portal Sandbox (Test twin)
- [ ] Order becomes paid (`processing` / `completed`); meta `_vexpay_payment_id` set

## Failures

- [ ] Wrong OTP → notice + order stays unpaid / failed note
- [ ] Invalid cédula at checkout → validation error

## Webhooks

Requires a URL VEXPay can reach (tunnel or staging host).

- [ ] Complete a payment and confirm `payment.completed` updates the order if the browser never finishes the OTP success redirect
- [ ] Tampered `X-Webhook-Signature` → 401

## Refunds

- [ ] Full refund on a COMPLETED C2P order → VEXPay reverse → order refunded
- [ ] Partial refund amount → error (unsupported in v1)

## Blocks

- [ ] Cart & Checkout Blocks: VEXPay fields render; order completes through OTP step
