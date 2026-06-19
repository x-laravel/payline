# Payline

[![Tests](https://github.com/x-laravel/payline/actions/workflows/tests.yml/badge.svg)](https://github.com/x-laravel/payline/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-11%20|%2012%20|%2013-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE.md)

A modern, DTO-based Laravel payment gateway abstraction layer. Driver packages for individual gateways (hoppa, iyzico, qnb-vpos) extend this core package.

## How It Works

- Implement the `Payable` interface on any model (Order, Invoice, etc.) to make it payable
- Add the `HasPayline` trait to get payment relationships and a `payWith()` shortcut
- Every gateway operation automatically creates a `Payment` + `Transaction` record in the database
- 3DS callbacks and server-to-server webhooks are handled via built-in routes
- Laravel Events are dispatched on every status change
- Driver packages register themselves via `extend()` — one line, no core changes needed

## Requirements

- PHP ^8.2
- Laravel ^12.0 | ^13.0
- At least one driver package (`x-laravel/payline-hoppa`, `x-laravel/payline-iyzico`, etc.)

## Installation

```bash
composer require x-laravel/payline
```

Run the migrations:

```bash
php artisan migrate
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag=payline-config
```

## Setup

### 1. Implement Payable

Add the `Payable` interface and `HasPayline` trait to any model you want to charge for:

```php
use Illuminate\Database\Eloquent\Model;
use XLaravel\Payline\Contracts\Payable;
use XLaravel\Payline\Traits\HasPayline;

class Order extends Model implements Payable
{
    use HasPayline;

    public function getPayableAmount(): int       { return $this->total; }
    public function getPayableCurrency(): string  { return $this->currency; }
    public function getPayableReference(): string { return $this->order_number; }
}
```

`HasPayline` provides default implementations for `getPayableCurrency()` (`'TRY'`), `getPayableCustomerEmail()`, `getPayableCustomerName()`, and `getPayableDescription()` — override only what you need.

### 2. Install a Driver

Install and configure at least one gateway driver. Refer to the driver package's README for gateway-specific setup.

```bash
composer require x-laravel/payline-iyzico
```

Set the default gateway in your `.env`:

```env
PAYLINE_DRIVER=iyzico
```

## Usage

### Making a Payment

```php
use XLaravel\Payline\DTOs\Card;
use XLaravel\Payline\DTOs\PaymentData;

$data = PaymentData::fromPayable($order, [
    'card' => new Card(
        number: '4111111111111111',
        holderName: 'John Doe',
        expiryMonth: '12',
        expiryYear: '2030',
        cvv: '123',
    ),
    'customerIp' => $request->ip(),
]);

$response = $order->pay('iyzico')->pay($data);
```

Or using the facade:

```php
use XLaravel\Payline\Facades\Payline;

$response = Payline::for($order)->via('iyzico')->pay($data);
```

### Handling the Response

```php
if ($response->isSuccessful()) {
    // Payment complete
    $response->gatewayTransactionId;
}

if ($response->requiresRedirect()) {
    // 3DS flow
    return redirect($response->redirectUrl);
    // or render a POST form: $response->redirectForm
}

if ($response->isFailure()) {
    $response->errorCode;
    $response->errorMessage;
}
```

### Authorization & Capture

```php
// 1. Reserve funds without capturing
$response = $order->pay()->authorize($data);

// 2. Capture later
use XLaravel\Payline\DTOs\CaptureData;

$response = Payline::via()->capture(
    new CaptureData(gatewayTransactionId: $transaction->gateway_transaction_id, amount: $transaction->amount),
    $payment,
    $transaction,
);
```

### Refund & Void

```php
use XLaravel\Payline\DTOs\RefundData;
use XLaravel\Payline\DTOs\VoidData;

// Partial or full refund
Payline::via()->refund(
    new RefundData(gatewayTransactionId: $transaction->gateway_transaction_id, amount: 5000, reason: 'Customer request'),
    $payment,
    $transaction,
);

// Void an authorization (before capture)
Payline::via()->void(
    new VoidData(gatewayTransactionId: $transaction->gateway_transaction_id),
    $payment,
    $transaction,
);
```

### Three Access Levels

```php
Payline::driver('iyzico')             // raw Gateway — no DB recording
Payline::via('iyzico')                // PendingPayment — recording + events
Payline::for($order)->via('iyzico')   // same, with a Payable bound
$order->pay('iyzico')                 // explicit driver via HasPayline trait
$order->pay()                         // auto-routing: cheapest gateway selected by GatewayRouter
```

## Commission Routing

Automatically route payments to the cheapest gateway based on card family, card type, and installment count. Rates are stored in the database (`payline_commission_rates`) and can be updated without deployment.

### Setup

Seed commission rates for each gateway:

```php
use XLaravel\Payline\Models\CommissionRate;

// Wildcard: applies to all card families / types
CommissionRate::create(['gateway' => 'hoppa', 'card_family' => null, 'card_type' => null, 'installments' => 1, 'rate' => 2.03]);

// Specific card family + type
CommissionRate::create(['gateway' => 'qnb', 'card_family' => 'CardFinans', 'card_type' => 'credit', 'installments' => 3, 'rate' => 2.92, 'blocking_days' => 3]);
CommissionRate::create(['gateway' => 'hoppa', 'card_family' => 'Bonus',     'card_type' => 'credit', 'installments' => 3, 'rate' => 2.03]);
```

Soft-delete a rate to deactivate it without losing history:

```php
CommissionRate::find($id)->delete();
```

### Usage

```php
use XLaravel\Payline\DTOs\Card;
use XLaravel\Payline\DTOs\CardProfile;
use XLaravel\Payline\Enums\CardType;

$data = PaymentData::fromPayable($order, [
    'card' => new Card(
        holderName: 'Ali Veli',
        number: '4111111111111111',
        expiryMonth: '12',
        expiryYear: '2030',
        cvv: '123',
        profile: new CardProfile('Bonus', CardType::Credit),
    ),
    'installments' => 3,
]);

// Auto-route — cheapest gateway is selected automatically
$order->pay()->pay($data);

// Explicit driver — skip routing
$order->pay('iyzico')->pay($data);

// Query directly
Payline::cheapestFor(new CardProfile('Bonus', CardType::Credit), installments: 3);
// → 'hoppa'

// Full ranked list
app(\XLaravel\Payline\Routing\GatewayRouter::class)
    ->rankedFor(new CardProfile('Bonus', CardType::Credit), 3);
// → ['hoppa' => 2.03, 'qnb' => 2.92]
```

**Matching priority:** Rows with exact `card_family` + `card_type` take precedence over wildcards (`null`). If no row matches, the configured default gateway is used.

## HasPayline Trait

Add `HasPayline` to any model to get relationships and helpers:

```php
$order->payments()            // MorphMany — all payments for this model
$order->successfulPayments()  // only successful ones
$order->pendingPayments()     // initiated + pending
$order->amountPaid()          // int — total charged (in kuruş)
$order->lastPayment()         // latest Payment model, or null
$order->pay()                 // start a payment (auto-route via GatewayRouter)
$order->pay('iyzico')         // start a payment (explicit driver)
```

## PaymentResponse

All gateway operations return a unified `PaymentResponse` DTO:

| Property | Type | Description |
|----------|------|-------------|
| `status` | `TransactionStatus` | initiated / pending / authorized / successful / failed / refunded / voided / expired |
| `type` | `TransactionType` | payment / authorization / capture / refund / void |
| `gatewayName` | `string` | |
| `gatewayTransactionId` | `?string` | |
| `gatewayOrderId` | `?string` | |
| `gatewayAuthCode` | `?string` | |
| `amount` | `int` | kuruş |
| `currency` | `string` | |
| `redirectUrl` | `?string` | 3DS redirect target |
| `redirectForm` | `?string` | POST form HTML |
| `errorCode` | `?string` | |
| `errorMessage` | `?string` | |
| `metadata` | `?array` | Gateway-specific extras |

Helper methods: `isSuccessful()`, `isPending()`, `isFailure()`, `requiresRedirect()`.

## Events

All events carry a `Payment` and `Transaction` model.

| Event | Fired when | Extra payload |
|-------|-----------|---------------|
| `PaymentInitiated` | Before the gateway call | `PaymentData` |
| `PaymentSucceeded` | Gateway confirms success | `PaymentResponse` |
| `PaymentFailed` | Gateway returns failure | `PaymentResponse` |
| `PaymentAuthorized` | Pre-authorization succeeds | `PaymentResponse` |
| `PaymentCaptured` | Capture succeeds | `PaymentResponse` |
| `PaymentRefunded` | Refund succeeds | `PaymentResponse` |
| `PaymentVoided` | Void succeeds | `PaymentResponse` |
| `WebhookReceived` | Webhook processed | `PaymentResponse` + raw payload |

```php
use XLaravel\Payline\Events\PaymentSucceeded;

class SendPaymentConfirmation
{
    public function handle(PaymentSucceeded $event): void
    {
        $event->payment->payable->sendConfirmationEmail();
    }
}
```

## Webhooks

Payline registers a CSRF-exempt webhook route automatically:

```
POST /payline/webhooks/{gateway}
```

Point your gateway's dashboard to this URL. The controller verifies the signature, parses the payload, finds the matching transaction, updates it, and dispatches `WebhookReceived`. Signature verification is handled per-driver via `Gateway::verifyWebhook()`.

## Models

### Payment

`payline_payments` — one record per checkout attempt.

```php
$payment->payable;                 // polymorphic — Order, Invoice, etc.
$payment->owner;                   // polymorphic — User, etc.
$payment->transactions();          // all gateway calls for this payment
$payment->latestTransaction();
$payment->successfulTransaction();
$payment->refunds();

$payment->isSuccessful();
$payment->isPending();
$payment->totalRefunded();         // int, kuruş
$payment->remainingRefundable();   // int, kuruş
$payment->nextAttemptNumber();
```

### Transaction

`payline_transactions` — one row per gateway API call (pay, capture, refund, void, webhook update).

```php
$transaction->payment;    // BelongsTo Payment
$transaction->parent;     // BelongsTo Transaction (refund/capture source)
$transaction->children(); // HasMany
```

## Writing a Driver

Implement `Gateway` and register it in your ServiceProvider:

```php
use XLaravel\Payline\Contracts\Gateway;

class MyGatewayDriver implements Gateway
{
    public function __construct(private array $config) {}

    public function pay(PaymentData $data): PaymentResponse { ... }
    public function authorize(PaymentData $data): PaymentResponse { ... }
    public function capture(CaptureData $data): PaymentResponse { ... }
    public function refund(RefundData $data): PaymentResponse { ... }
    public function void(VoidData $data): PaymentResponse { ... }
    public function handleCallback(CallbackData $data): PaymentResponse { ... }
    public function verifyWebhook(array $payload, string $signature): bool { ... }
    public function parseWebhook(array $payload): PaymentResponse { ... }
    public function supportedMethods(): array { return [PaymentMethod::CreditCard]; }
    public function getName(): string { return 'my-gateway'; }
}
```

Register in your driver package's ServiceProvider:

```php
public function boot(): void
{
    $this->app->make('payline')->extend('my-gateway', function ($app, array $config) {
        return new MyGatewayDriver($config);
    });
}
```

The `$config` array is automatically injected from `config('payline.gateways.my-gateway')`.

## Configuration

```php
// config/payline.php
return [
    'default' => env('PAYLINE_DRIVER', 'hoppa'),

    'gateways' => [
        'iyzico' => [
            'api_key'    => env('IYZICO_API_KEY'),
            'secret_key' => env('IYZICO_SECRET_KEY'),
            'base_url'   => env('IYZICO_BASE_URL', 'https://sandbox-api.iyzipay.com'),
        ],
    ],

    'routes' => [
        'enabled'            => true,
        'prefix'             => 'payline',
        'middleware'         => ['web'],
        'webhook_middleware' => [],
    ],
];
```

## Database

```
payline_payments
├── id                (ulid)
├── payable_type/id   (polymorphic — Order, Invoice, etc.)
├── owner_type/id     (polymorphic — User, etc.)
├── gateway
├── status            (TransactionStatus)
├── amount            (int, kuruş)
├── currency          (char 3)
├── reference         (order number, invoice id, etc.)
├── metadata          (json, nullable)
└── timestamps

payline_transactions
├── id                       (ulid)
├── payment_id               (FK → payline_payments, cascadeOnDelete)
├── type                     (TransactionType)
├── status                   (TransactionStatus)
├── amount, currency
├── attempt                  (int — retry counter)
├── gateway_transaction_id   (indexed)
├── gateway_order_id         (indexed)
├── gateway_auth_code
├── gateway_response_code/message
├── error_code/message
├── redirect_url
├── parent_transaction_id    (FK → payline_transactions, for refunds/captures)
├── metadata                 (json, nullable)
└── timestamps

payline_webhook_logs
├── id               (ulid)
├── gateway
├── event_type
├── gateway_event_id (unique per gateway)
├── payload          (json)
├── status           (received / processing / processed / failed)
├── exception        (text, nullable)
├── processed_at
└── timestamps

payline_commission_rates
├── id              (ulid)
├── gateway         ('hoppa', 'qnb', 'iyzico'…)
├── card_family     (string, nullable — null = wildcard)
├── card_type       ('credit' / 'debit' / 'foreign_credit', nullable — null = wildcard)
├── installments    (int, default 1)
├── rate            (decimal 8,4 — e.g. 2.9200 means 2.92%)
├── blocking_days   (int, nullable)
├── deleted_at      (soft delete — null = active)
└── timestamps
```

## Testing

```bash
# Build first (once per PHP version)
DOCKER_BUILDKIT=0 docker compose --profile php82 build

# Run tests
docker compose --profile php82 up
docker compose --profile php83 up
docker compose --profile php84 up
```

## License

This package is open-sourced software licensed under the [MIT license](https://opensource.org/license/MIT).
