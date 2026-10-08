# KioskPay — M-Pesa Payments (WordPress plugin)

Accept M-Pesa payments on WordPress through [KioskPay](https://kioskpay.co.ke/wordpress/).
Sign up at [kioskpay.co.ke](https://kioskpay.co.ke), copy your three codes, no
Daraja app. Ships a **WooCommerce gateway** and a standalone **`[malipo_pay]`**
shortcode, both built on the same client and webhook.

## What it does

- Prompts the customer's phone with an M-Pesa STK push (`POST /v1/payments`).
- Verifies every callback with HMAC-SHA256 over the raw body
  (`X-Malipo-Signature: sha256=…`) using your `whsec_…` secret.
- Never trusts the callback body on its own — it confirms with
  `GET /v1/payments/{id}` and marks the order paid **only** when that returns
  `settled`. The M-Pesa receipt is stored on the order/record.
- Falls back to polling on the order-received page, so it works even if the
  webhook never arrives.
- Uses one idempotency key per attempt, so a retried request never double-charges.

## Install

Copy this folder into `wp-content/plugins/`, or zip it and upload it via
**Plugins → Add New → Upload Plugin**, then activate **KioskPay — M-Pesa Payments**.

There is no build step. Requires WordPress 6.0+, PHP 7.4+. WooCommerce is optional.

## Updates

The plugin updates itself from **kioskpay.co.ke**, the same way a wordpress.org
plugin does — new versions appear under **Dashboard → Updates** with an
**Update now** link. No manual re-uploading.

It reads a small JSON manifest:

```
https://kioskpay.co.ke/malipo-payments.json
```

```json
{
  "version": "0.1.3",
  "package": "https://kioskpay.co.ke/malipo-payments-0.1.3.zip",
  "requires": "6.0",
  "tested": "6.7",
  "requires_php": "7.4",
  "changelog": "What changed in this release."
}
```

To ship a release, run `./build.sh <version>` — it lints every PHP file, stamps
the version, zips the plugin and writes the manifest. Pass `--no-lint` when PHP is
not installed. Then upload `dist/malipo-payments-<version>.zip` and
`dist/malipo-payments.json` to the site root. The manifest is cached for 6 hours
(1 hour after a miss). Point it somewhere else with the `MALIPO_UPDATE_MANIFEST`
constant or the `malipo_update_manifest_url` filter.

## Configure

Sign up at [kioskpay.co.ke](https://kioskpay.co.ke), add where money should land,
then go to **Settings → KioskPay** and paste the three codes it shows you:

| Field | Example | Notes |
|---|---|---|
| Client ID | `pk_live_…` | Your public shop ID. Not a secret — safe to keep in your configuration. |
| Payment key | `sk_live_…` | Shown once. Kept on your server. |
| Signing code | `whsec_…` | Verifies callback signatures. |
| API address | `https://backend.kioskpay.co.ke` | Leave as it is unless KioskPay tells you otherwise. |

When a Client ID and Payment key are both set, the plugin authenticates with HTTP
Basic (`client_id:client_secret`); without a Client ID it sends the Payment key as
`Authorization: Bearer …`. Either way the key never leaves your server.

The page also shows your **Callback URL** — paste it into KioskPay if your
account asks for one. The plugin sends it as `callback_url` on every payment.

## WooCommerce

1. **WooCommerce → Settings → Payments → M-Pesa (KioskPay)** → enable.
2. Checkout collects the customer's M-Pesa phone, creates the payment, and puts
   the order **on hold**.
3. When the payment settles, the order is completed (receipt stored as the
   transaction id); when it fails, the order is marked failed.

The gateway only appears when a payment key is set and the store currency is KES.

## Shortcode

Charge any amount from a page, post, or block:

```
[malipo_pay amount="100" reference="donation-42" button="Pay KES 100"]
```

Attributes: `amount` (required), `reference`, `title`, `button`. The form collects
a phone number, creates the payment, and polls until it settles or fails.

Each payment is recorded as a **KioskPay Payment** under the **KioskPay** admin menu.

## How it fits together

```
malipo-payments.php        plugin bootstrap
includes/
  class-malipo-util.php       phone normalisation + formatting
  class-malipo-api.php        POST /v1/payments, GET /v1/payments/{id}
  class-malipo-settings.php   Settings → KioskPay
  class-malipo-payments.php   payment records (CPT) + create/refresh
  class-malipo-rest.php       /pay, /status/{token}, /webhook
  class-malipo-shortcode.php  [malipo_pay] rendering
  class-malipo-gateway.php    WooCommerce gateway (loaded only if WooCommerce)
  class-malipo-updater.php    self-updates from kioskpay.co.ke
assets/
  css/malipo.css
  js/malipo-pay.js
```

REST routes (`/wp-json/malipo/v1/…`):

| Route | Purpose |
|---|---|
| `POST /pay` | Create a payment from the shortcode (nonce-protected). |
| `GET /status/{token}` | Poll a payment by its unguessable token. |
| `POST /webhook` | KioskPay callback; signature-verified, then confirmed via GET. |

## Security notes

- Callbacks are rejected with `401` unless the signature matches your
  `webhook_secret`. A missing secret yields `500` rather than trusting the body.
- Status polling uses a random 32-hex token, not a guessable id.
- The API secret key is stored in the WordPress options table; restrict
  `manage_options` as usual.

## Trying it

Set the API base URL to `http://127.0.0.1:4000` (a local Malipo service, see
`../../service`), paste the `sk_live_…` from your KioskPay account, and send a KES 1
test from the shortcode or checkout. Local `http://localhost` callback URLs are
accepted by Malipo, so the webhook path is testable too.
