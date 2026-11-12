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
- [ ] Order becomes `on-hold`; redirect to dedicated OTP page (`wc-api=vexpay_otp_form`), not Blocks “Pay for order”
- [ ] Enter sandbox OTP from VEXPay Portal Sandbox (Test twin)
- [ ] Order becomes paid (`processing` / `completed`); meta `_vexpay_payment_id` set

## Failures

- [ ] Wrong OTP → notice + order stays unpaid / failed note
- [ ] Invalid cédula at checkout → validation error

## Settlement poller (no webhooks)

Useful when the store URL is not reachable from VEXPay (local wp-env without a tunnel).

- [ ] Complete a débito that stays `PENDING` / `AC00` after OTP (or simulate via API)
- [ ] Confirm order has `_vexpay_payment_id` and `_vexpay_status` = `PENDING`
- [ ] Wait for Action Scheduler / WP-Cron (`vexpay_poll_pending_payments`) — or run `wp cron event run vexpay_poll_pending_payments`
- [ ] Order becomes paid or failed; log shows per-order attempt + status transition when Debug is on
- [ ] Pre-OTP on-hold orders (no `_vexpay_payment_id`) log `skip … missing_payment_id` and do not call the API

## Webhooks

Requires a URL VEXPay can reach (tunnel or staging host). Prefer webhooks in production; poller is an interim fallback.

- [ ] Complete a payment and confirm `payment.completed` updates the order if the browser never finishes the OTP success redirect
- [ ] Tampered `X-Webhook-Signature` → 401

## Refunds

- [ ] Full refund on a COMPLETED C2P order → VEXPay reverse → order refunded
- [ ] Partial refund amount → error (unsupported in v1)

## Blocks

- [ ] Cart & Checkout Blocks: VEXPay fields render; order completes through OTP step

## Marketplace splits (1.3.0)

Needs a VEX Pay tenant set up as a marketplace, with at least two active test-mode comercios.

Mode off (regression, run first):

- [ ] **Marketplace splits** unchecked; the product edit screen has no "Comercio (VEX Pay)" field
- [ ] Débito and card orders complete as above; no request body contains `splits` (Debug log + API logs) and orders have no `_vexpay_splits` meta
- [ ] USDT is offered as before

Mode on:

- [ ] Check **Marketplace splits**, save; the product General tab and each variation show "Comercio (VEX Pay)" listing the active comercios; **Refresh comercio list** reloads it
- [ ] Map plan A → comercio A, plan B → comercio B; leave plan C unmapped
- [ ] One comercio: débito order with plan A only → execute body has one share; order box "VEX Pay split" shows it
- [ ] Two comercios + shipping: A + B + shipping → two shares in VES at the order quote (rounded down); shipping stays with the marketplace (box remainder row)
- [ ] Coupon: 10% cart coupon → shares follow the discounted line totals
- [ ] Card (VPOS): A + B → two shares in USD with `usdAmount`
- [ ] USDT hidden in classic and block checkout when the cart has a comercio item; shown again for a cart with only plan C
- [ ] After payment, the box shows commission, VEX Pay fee and net per comercio (from the response or `payment.completed` webhook); nets match share × (1 − VEX Pay rate − commission %)
- [ ] Deactivate comercio A in VEX Pay, pay A by débito → customer sees "Este plan no está disponible para pago en este momento", order failed with a note naming the comercio, no money taken, no second request without splits
- [ ] `vexpay_upsert_merchant()` twice with the same `external_ref` → one VEX Pay merchant, updated commission
