# Infynipay — J2Commerce Payment Plugin

A standard Joomla 4 / 5 / 6 payment plugin that integrates **Infynipay** hosted-page checkout with **J2Commerce 6.x**. Buyers can pay with credit card, Apple Pay, Google Pay and any provider you connect in your Infynipay dashboard.

| Item       | Value                                                                |
| ---------- | -------------------------------------------------------------------- |
| Joomla     | 4.x / 5.x / 6.x                                                      |
| J2Commerce | 6.x                                                                  |
| PHP        | 8.1+                                                                 |
| Plugin     | `plg_j2commerce_payment_infynipay`                                 |
| Namespace  | `J2Commerce\Plugin\J2Commerce\PaymentInfynipay`                    |
| Main class | `Infynipay` (in `src/Extension/Infynipay.php`)                   |
| Element    | `payment_infynipay` (group `j2commerce`)                           |

## Features

- Hosted-page checkout — PCI-friendly redirect flow.
- Multi-provider grid rendered on the J2Commerce payment page.
- Sandbox + Live credentials stored side-by-side; switch with one toggle.
- One-click OAuth activation — no manual API keys.
- Server-to-server webhook with `Bearer` token verification.
- Idempotent order-status sync (webhook + return-page fallback).
- Custom admin fields:
  - `Setupbutton` — connect / manage Infynipay gateways.
  - `Providers` — supported providers preview.
  - `Logviewer` — shows the absolute log-file path.
- Geo-zone restriction + min / max subtotal limits.
- Full Joomla logging into `plg_j2commerce_payment_infynipay.php`.

## Installation

1. Build a zip of this folder:

   ```powershell
   Compress-Archive -Path * -DestinationPath plg_j2commerce_payment_infynipay.zip
   ```

2. Install via **System → Install → Extensions** in Joomla.
3. Enable the plugin at **System → Plugins → `Infynipay`** (group `j2commerce`).

## Configuration

1. Open the plugin settings.
2. Click **Connect** in the Infynipay card to start the OAuth flow.
3. After the redirect, sandbox and live credentials are stored automatically (and the webhook token is registered with Infynipay).
4. Toggle **Sandbox mode** to switch environments.
5. (Optional) Map order statuses for *success / pending / failed / cancelled*.
6. (Optional) Restrict by geo-zone or subtotal limits.

## Webhook

Infynipay calls this URL with `Authorization: Bearer <secret>`:

```
{SITE_URL}/index.php?option=com_ajax&plugin=infynipay&group=j2commerce&format=raw&action=webhook
```

The secret is generated and registered automatically during activation. The webhook handler verifies the token, calls Infynipay's `GET /api/apps/{appId}/payments/referenceId:{orderId}` for authoritative state, and updates the order — idempotently.

> **Localhost:** webhooks cannot reach a private address. The plugin shows a banner and falls back to status sync on the buyer's return page (terminal states only — never downgrades to pending).

## Folder layout

```
payment_infynipay.xml            — plugin manifest
payment_infynipay.php            — bootstrap stub
script.php                         — installer script (PHP 8.1+ check)
services/provider.php              — DI service provider
src/Extension/Infynipay.php
src/Field/{Setupbutton,Providers,Logviewer}Field.php
layouts/field/{setupbutton,providers}.php
tmpl/{prepayment,postpayment,message}.php
language/en-GB/plg_j2commerce_payment_infynipay.ini
language/en-GB/plg_j2commerce_payment_infynipay.sys.ini
media/css/{setupbutton,providers}.css
media/js/setupbutton.js
images/logos/infynipay-logo-{light,dark}.png
```

## Events handled

| Event                                   | Purpose                                             |
| --------------------------------------- | --------------------------------------------------- |
| `onJ2CommerceGetPaymentPlugins`         | Advertise the plugin in the payment-method picker   |
| `onJ2CommerceGetPaymentOptions`         | Geo-zone + subtotal gate                            |
| `onJ2CommerceCalculateFees`             | No surcharge applied                                |
| `onJ2CommerceAcceptSubscriptionPayment` | Returns `false` (no subscriptions)                  |
| `onJ2CommercePrePayment`                | Render the Infynipay card                         |
| `onJ2CommercePostPayment`               | Display / cancel / process / callback               |
| `onJ2CommerceProcessWebhook`            | Server-to-server IPN                                |
| `onAjaxInfynipay`                     | OAuth callback, checkout-methods JSON, pay redirect |
| `onBeforeRender`                        | Inject the provider-icon strip at checkout          |

## About InfyniPay

[InfyniPay](https://www.infynipay.com) is a payment orchestration platform that connects your store to 30+ payment providers through a single free, open-source plugin — with automatic routing, failover, webhook verification and server-side confirmation handled for you. Add or switch providers from a dashboard without touching code.

- 🔌 **One integration, 30+ providers** — PayPal, Stripe, Adyen, Paystack, Razorpay, Binance Pay and more
- 🆓 **Free & open-source** — no per-transaction fee from InfyniPay; you pay only your provider's processing fees
- 🔁 **Automatic routing & failover** between providers
- 🛡️ **Signed webhooks + server-side verification** built in

**Links:** [Website](https://www.infynipay.com) · [J2Commerce integration](https://www.infynipay.com/integrations/j2commerce/) · [Docs](https://docs.infynipay.com) · [All integrations](https://www.infynipay.com/integrations/) · [Create a free account](https://console.infynipay.com/auth/signup)

## License

GNU General Public License v2 or later.
