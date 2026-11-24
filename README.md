# molefigog/laravel-payments

One Laravel service provider for **M-Pesa (Vodacom LS), EcoCash (PayLesotho), Chaperone CPay and PayPal**.
Your app only ever says *"charge this user for product 5 through gateway X"*. The package never
knows what a product is, so the same code serves POS, OTT or anything else.

- `product_id` only (string column, nullable). No product type, no `music_id` / `service_id`.
- Server-side pricing through one small `ProductResolver` you write per project.
- Same flow for every gateway: `charge → (otp | redirect | poll) → completed`.
- Your business logic runs in **event listeners**, never inside payment code.
- Gateways live in config/.env **or** the `payment_gateways` table (encrypted credentials) so a UI can manage them.
- Safe by default: completed is final, so a retried webhook fires `PaymentCompleted` once.

## Install

```bash
composer require molefigog/laravel-payments
php artisan migrate
php artisan vendor:publish --tag=payments-config
php artisan payments:seed-gateways         
```

Local development before publishing, in the host app's `composer.json`:

```json
"repositories": [{ "type": "path", "url": "../laravel-payments" }]
```
then `composer require molefigog/laravel-payments:@dev`.

Auto-discovery registers `PaymentsServiceProvider`. Nothing else to wire.

## Configure

Gateways read the same env names Musik already uses:

| Gateway | .env |
|---|---|
| M-Pesa | `PESA_API_KEY`, `PESA_PUBLIC_KEY`, `PESA_SC`, `PESA_ENV` (`sandbox`/`live`) |
| EcoCash | `ECOCASH_MERCHANT_ID`, `ECOCASH_MERCHANT_NAME`, `ECOCASH_TOKEN` |
| CPay | `CPAY_API`, `CPAY_SECRET`, `CPAY_CODE`, `CPAY_URL`, `CPAY_MODE` |
| PayPal | `PAYPAL_CLIENT_ID`, `PAYPAL_SECRET`, `PAYPAL_MODE`, `PAYPAL_FX_SOURCE=ZAR` (LSL is pegged to ZAR) |

Also set `PAYMENTS_RESULT_URL` (where the browser returns after PayPal / CPay card) and the
`allowed_redirect_hosts` / `allowed_redirect_schemes` in `config/payments.php`. Redirects outside that list are refused.

A `payment_gateways` row with the same slug overrides config. Credentials in the DB are encrypted
(`encrypted:array`). A slug may use any driver, e.g. `cpay-card` with `driver=cpay, settings.method=card`.

## Usage per project: a resolver

```php
use Molefigog\Payments\Contracts\ProductResolver;
use Molefigog\Payments\Support\Product;

class MusikProducts implements ProductResolver
{
    public function resolve(array $ids, array $context = []): array
    {
        return Music::with('release')->findMany($ids)->map(fn ($m) => new Product(
            id: $m->id,
            title: $m->title,
            amount: (float) $m->price,            // the SERVER decides the price
            payeeId: $m->release?->user_id,       // seller; stored as payee_id
        ))->all();
    }
}
```

```php
// config/payments.php
'resolver' => App\Payments\MusikProducts::class,
```

If you have two product tables whose ids can collide, return namespaced ids (`'music:5'`, `'service:2'`)
from your resolver and parse them there. `product_id` is a string for exactly this reason.
Without a resolver the package accepts a client `amount`; do that only for server-to-server use.

## Use it

```php
use Molefigog\Payments\Traits\HasPayments;  

$result = $user->charge([12, 15], 'mpesa', ['msisdn' => '58123456']);
$result->status;     // pending | completed | failed
$result->action;     // poll | otp | redirect | html | none
$result->reference;  // PAY2611241530ABCDE, one per charge (shared by its rows)
```

Or over HTTP (default prefix `api/payments`, auth `auth:sanctum`):

| Route | Purpose |
|---|---|
| `GET gateways` | enabled gateways + public config (e.g. PayPal client id) |
| `POST charge` | `{gateway, product_id \| product_ids[], msisdn?, return_url?, email?, method?}` |
| `POST confirm` | `{reference, otp}` (CPay) or `{reference, order_id}` (PayPal JS SDK) |
| `GET status/{reference}` | current state; also nudges the provider if pending |
| `ANY callback/{gateway}` | provider callbacks and browser returns (no auth) |

What the client does next, from `action`: `poll` → call `status/{reference}` every few seconds
(M-Pesa, EcoCash) · `otp` → collect OTP, call `confirm` (CPay mobile) · `redirect` / `html` → send the
browser to `redirect_url` / render `html` (PayPal, CPay card).

## Your logic goes in listeners

```php
Event::listen(PaymentCompleted::class, function (PaymentCompleted $e) {
    foreach ($e->payments as $payment) {          // one row per product
        app(WalletService::class)->creditSeller($payment->payee_id, $payment->amount);
        // create the Task, send the invoice, unlock the download ...
    }
});
```

Events: `PaymentInitiated`, `PaymentCompleted`, `PaymentFailed`. Each fires once per charge.

## Moving Musik onto it

1. Install; `php artisan migrate`. The migrations **adopt** your existing `payments` and
   `payment_gateways` tables (adds columns, backfills `gateway ← type`, `product_id ← music_id/service_id`,
   `payee_id ← seller_id`, `reference ← txn_id`). Old columns stay until you drop them.
2. Write the resolver above. Services with no id: return a `Product` with your own id scheme (e.g. `service:beat`).
3. Move `TaskProvisioningService`, `CreditSellerForPayment` and `SendPaymentInvoice::dispatchFor` into a `PaymentCompleted` listener.
4. Point the Quasar app at the new routes, then delete `PaymentsController`, `CpayService`, `PayPalService`,
   `VclController`, `VclService`, `PollMpesaTransactionStatus`.

| Old | New |
|---|---|
| `/v1/payments/mpesa/{music,services}` | `POST charge` `{gateway:'mpesa'}` |
| `/v1/payments/ecocash/{music,services}` | `POST charge` `{gateway:'ecocash'}` |
| `/v1/cpay/pay`, `/v1/cpay/confirm`, `/v1/cpay/card` | `POST charge` (`method:'card'` for card), `POST confirm` |
| `/v1/paypal/*/create-order`, `capture-order` | `POST charge` `{gateway:'paypal'}`, `POST confirm {order_id}` |
| `/v1/payments/mpesa/status` | `GET status/{reference}` |
| `/v1/payment-gateways`, `/v1/paypal/config` | `GET gateways` |

## Operations

- **Polling** needs a real queue (`database`, `redis`). With `QUEUE_CONNECTION=sync` it is skipped and clients poll `status/{reference}`.
- `php artisan payments:reconcile` re-checks payments still pending; schedule it every few minutes.
- Add a gateway: implement `Contracts\PaymentGateway` (extend `Gateways\AbstractGateway`), then
  `app(GatewayManager::class)->extend('mydriver', MyGateway::class)` or add it under `payments.drivers`.

## Honest status (v0.1)

- Syntax-checked on PHP 8.3 and the pure logic (MSISDN formats, redirect allow-list, amount splitting,
  resolver pricing, M-Pesa key encryption) is smoke-tested. **It has not been run against live or sandbox
  provider APIs, or inside a Laravel app.** Test each gateway in sandbox before production.
- Gateway calls are ported from the working Musik code. Behaviour changes you should know about:
  PayPal counts only `COMPLETED` captures (not `APPROVED`) and checks the captured amount; EcoCash keeps
  polling instead of failing on the first unsuccessful verify (times out after 180 s); the CPay and
  M-Pesa references are generated by the package; the CPay callback is unsigned in the original
  integration, so ask Chaperone whether a signature exists. CPay's transaction-status response format
  is undocumented in the old code, so `status()` only treats `TransactionComplete` as success.
- Not included yet: the Filament management page (the models and tables are ready for it),
  tests with Orchestra Testbench, and a JS/Quasar client.

MIT
