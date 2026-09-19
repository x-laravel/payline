# Payline

[![Tests](https://github.com/x-laravel/payline/actions/workflows/tests.yml/badge.svg)](https://github.com/x-laravel/payline/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%20%7C%2013-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](https://opensource.org/license/MIT)

Payline is a reusable payment orchestration layer for Laravel. Gateway packages implement small, operation-specific contracts while applications receive one consistent API for payments, authorization, capture, refund, void, 3DS callbacks, webhooks, idempotency, routing, and reconciliation.

Every recorded operation creates or updates a `Payment` and `Transaction`, validates the state transition, and dispatches Laravel events.

## Requirements

- PHP 8.3 or newer
- Laravel 12 or 13
- A Payline gateway driver

Amounts are integers in the currency's minor unit. For example, `10000` represents TRY 100.00.

## Installation

```bash
composer require x-laravel/payline
php artisan vendor:publish --tag=payline-migrations
php artisan migrate
```

Payline does not register its migrations from the package directory. Publishing them is required, and it leaves the schema under the application's control.

Publish the configuration when the defaults need changing:

```bash
php artisan vendor:publish --tag=payline-config
```

```env
PAYLINE_DRIVER=iyzico
```

Verify the installation:

```bash
php artisan payline:doctor
```

## Making a model payable

```php
use Illuminate\Database\Eloquent\Model;
use XLaravel\Payline\Contracts\Payable;
use XLaravel\Payline\Traits\HasPayline;

class Order extends Model implements Payable
{
    use HasPayline;

    public function getPayableReference(): string
    {
        return $this->order_number;
    }

    public function getPayableAmount(): int
    {
        return $this->total;
    }
}
```

`HasPayline` supplies these optional defaults:

- `getPayableCurrency()`: `TRY`
- `getPayableCustomerEmail()`: the model's `email` attribute
- `getPayableCustomerName()`: the model's `name` attribute
- `getPayableDescription()`: `null`

## Creating a payment

```php
use XLaravel\Payline\DTOs\Card;

$card = new Card(
    holderName: 'Jane Doe',
    number: '4111111111111111',
    expiryMonth: '12',
    expiryYear: '2030',
    cvv: '123',
);

$response = $order->pay('iyzico')
    ->card($card)
    ->customerIp($request->ip())
    ->idempotencyKey((string) str()->uuid())
    ->charge();
```

Reference, amount, currency, customer name, customer email, and description come from the payable. Each one can be overridden on the chain, which also covers charging less than the payable total:

```php
$order->pay('iyzico')->card($card)->amount(5000)->charge();
```

The facade exposes the same recorded workflow:

```php
use XLaravel\Payline\Facades\Payline;

$response = Payline::for($order)
    ->via('iyzico')
    ->card($card)
    ->charge();
```

Without a payable, supply the values the request needs:

```php
Payline::via('iyzico')
    ->reference('INV-2026-1')
    ->amount(10000)
    ->card($card)
    ->charge();
```

The chain accepts `reference()`, `amount()`, `currency()`, `card()`, `cardToken()`, `saveCard()`, `method()`, `installments()`, `threeDs()`, `withoutThreeDs()`, `customerEmail()`, `customerName()`, `customerPhone()`, `customerIp()`, `description()`, `callbackUrl()`, `basketItems()`, `billingAddress()`, `shippingAddress()`, `metadata()`, `cardProfile()`, and `idempotencyKey()`.

`charge()` and `authorize()` also accept a `PaymentRequest` the application builds itself:

```php
use XLaravel\Payline\DTOs\PaymentRequest;

$response = $order->pay('iyzico')->charge(
    PaymentRequest::fromPayable(payable: $order, card: $card),
);
```

`Payline::driver('iyzico')` returns the raw gateway. Raw calls bypass Payline's persistence, validation, idempotency, and events, so application code should normally use `via()`, `for()`, or `pay()`.

## Handling responses

```php
if ($response->requiresRedirect()) {
    return $response->redirectForm !== null
        ? response($response->redirectForm)
        : redirect()->away($response->redirectUrl);
}

if ($response->isApproved()) {
    $gatewayTransactionId = $response->gatewayTransactionId;
}

if ($response->isFailure()) {
    logger()->warning('Payment failed', [
        'code' => $response->errorCode,
        'message' => $response->errorMessage,
    ]);
}
```

`PaymentResponse` provides `isSuccessful()`, `isApproved()`, `isPending()`, `isFailure()`, and `requiresRedirect()`.

## Idempotency

Use a stable key for every retryable operation:

```php
$order->pay('iyzico')
    ->card($card)
    ->idempotencyKey("order:{$order->getKey()}:payment")
    ->charge();
```

Repeating an operation with the same key and payload returns the recorded result without calling the provider again. Reusing a key with a different fingerprinted payload throws `IdempotencyConflictException`. Capture, refund, and void operations accept independent idempotency keys.

## Authorization and follow-up operations

```php
$response = $order->pay('iyzico')->card($card)->authorize();
```

Use the recorded `Payment` for subsequent operations. Payline automatically uses the original gateway and rejects invalid state transitions or excessive amounts:

```php
use XLaravel\Payline\Facades\Payline;

$capture = Payline::payment($payment)->capture(
    amount: 10000,
    idempotencyKey: "payment:{$payment->id}:capture:1",
);

$refund = Payline::payment($payment)->refund(
    amount: 2500,
    reason: 'Customer request',
    idempotencyKey: "payment:{$payment->id}:refund:1",
);

$void = Payline::payment($payment)->void(
    idempotencyKey: "payment:{$payment->id}:void:1",
);
```

## Gateway selection and routing

Select a gateway explicitly:

```php
$order->pay('iyzico')->card($card)->charge();
Payline::via('iyzico')->card($card)->reference('INV-1')->amount(10000)->charge();
```

Omit the gateway to use commission routing. Provide a card profile directly or resolve it through a registered BIN lookup driver:

```php
use XLaravel\Payline\BinLookupManager;

$card = $card->resolveProfile(app(BinLookupManager::class));

$response = $order->pay()
    ->card($card)
    ->installments(3)
    ->charge();
```

Commission rates are stored in `payline_commission_rates`. Exact card family and type matches take precedence over wildcard rows. If no suitable rate exists, Payline uses the default gateway.

Routing also filters gateways through `GatewayCapabilities` and the classes configured in `payline.routing.policies`. A policy implements `GatewayRoutingPolicy`:

```php
use XLaravel\Payline\Contracts\Gateway;
use XLaravel\Payline\Contracts\GatewayRoutingPolicy;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\Enums\TransactionType;

class CurrencyPolicy implements GatewayRoutingPolicy
{
    public function allows(
        Gateway $gateway,
        PaymentRequest $request,
        TransactionType $operation,
    ): bool {
        return $request->currency === 'TRY';
    }
}
```

```php
'routing' => [
    'policies' => [CurrencyPolicy::class],
],
```

## Callbacks and webhooks

Payline registers these routes when `payline.routes.enabled` is `true`:

```text
GET|POST /payline/callback/{gateway}
POST     /payline/webhooks/{gateway}
```

If a payment request has no callback URL, Payline adds its callback route automatically. Redirect destinations can be configured globally or per gateway:

```php
'callback_success_url' => '/payments/success',
'callback_failure_url' => '/payments/failure',

'gateways' => [
    'iyzico' => [
        'callback_success_url' => '/iyzico/success',
        'callback_failure_url' => '/iyzico/failure',
    ],
],
```

Webhooks are verified before storage or processing. Provider event IDs are deduplicated per gateway; when no event ID is available, Payline uses a fingerprint of the raw request body. Sensitive payload keys are redacted before persistence and event dispatch.

Drivers that sign the raw request must implement `HandlesRawWebhooks`. Providers that sign a normalized array payload implement `HandlesWebhooks` instead. A driver implementing neither cannot receive webhooks. The webhook route is CSRF-exempt and uses `throttle:60,1` by default.

## Reconciliation

Provider timeouts and ambiguous errors are recorded as `unknown` instead of being treated as declined payments. Drivers implementing `QueriesPayments` can reconcile pending and unknown records:

```php
$response = Payline::payment($payment)->reconcile();
```

```bash
php artisan payline:reconcile
php artisan payline:reconcile --gateway=iyzico --limit=50
```

## Models and events

`Payment` represents the aggregate state of a checkout attempt. `Transaction` represents an individual provider operation.

```php
$payment->transactions();
$payment->latestTransaction();
$payment->refunds();
$payment->wasSuccessful();
$payment->hasOutstandingAmount();
$payment->isPending();
$payment->totalRefunded();
$payment->remainingRefundable();

$order->payments();
$order->successfulPayments();
$order->pendingPayments();
$order->amountPaid();
$order->amountRefunded();
$order->amountNet();
$order->lastPayment();
```

Lifecycle events are dispatched only when the recorded status changes:

- `PaymentInitiated`
- `PaymentPending`
- `PaymentSucceeded`
- `PaymentFailed`
- `PaymentErrored`
- `PaymentAuthorized`
- `PaymentCaptured`
- `PaymentRefunded`
- `PaymentVoided`
- `WebhookReceived`
- `CallbackUnmatched`

## Writing a gateway driver

Every driver implements `Gateway` and only the operation contracts it supports:

```php
use XLaravel\Payline\Contracts\ChargesPayments;
use XLaravel\Payline\Contracts\Gateway;
use XLaravel\Payline\Contracts\ProvidesGatewayCapabilities;
use XLaravel\Payline\DTOs\GatewayCapabilities;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\Enums\PaymentMethod;
use XLaravel\Payline\Enums\TransactionType;

class MyGateway implements Gateway, ChargesPayments, ProvidesGatewayCapabilities
{
    public function __construct(private array $config) {}

    public function getName(): string
    {
        return 'my-gateway';
    }

    public function supportedMethods(): array
    {
        return [PaymentMethod::CreditCard];
    }

    public function pay(PaymentRequest $data): PaymentResponse
    {
        return $this->createPayment($data);
    }

    public function capabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(
            operations: [TransactionType::Payment],
            methods: [PaymentMethod::CreditCard],
            currencies: ['TRY'],
            installments: [1, 2, 3],
            threeDs: true,
            nonThreeDs: false,
        );
    }
}
```

Available operation contracts:

- `ChargesPayments`
- `AuthorizesPayments`
- `CapturesPayments`
- `RefundsPayments`
- `VoidsPayments`
- `HandlesCallbacks`
- `HandlesWebhooks` or `HandlesRawWebhooks`
- `QueriesPayments`
- `ProvidesGatewayCapabilities`

Payline dispatches every operation through these contracts. A driver that defines a matching method without implementing the contract is rejected with a `LogicException`.

Register the driver from its service provider:

```php
public function boot(): void
{
    $this->app->make('payline')->extend(
        'my-gateway',
        fn ($app, array $config) => new MyGateway($config),
    );
}
```

The driver receives `config('payline.gateways.my-gateway')` as its configuration array.

## Configuration and security

Important `config/payline.php` options:

```php
return [
    'default' => env('PAYLINE_DRIVER'),

    'currency' => env('PAYLINE_CURRENCY', 'TRY'),

    'routes' => [
        'enabled' => true,
        'prefix' => 'payline',
        'middleware' => ['web'],
        'webhook_middleware' => ['throttle:60,1'],
    ],

    'routing' => [
        'policies' => [],
    ],

    'database' => [
        'connection' => env('PAYLINE_DB_CONNECTION', env('DB_CONNECTION', 'sqlite')),
    ],

    'storage' => [
        'card_details' => true,
        'card_holder_name' => true,
        'webhook_payload' => true,
    ],
];
```

Set `PAYLINE_DB_CONNECTION` to use a dedicated Laravel database connection. Disable storage fields the application does not need. `payline.currency` applies only when a charge has neither a payable nor an explicit `currency()`.

Payline never stores the complete card number or CVV. Optional card storage is limited to BIN, last four digits, and cardholder name. `Card` masks sensitive fields in debug and JSON output.

Models can be replaced through `payline.models`, or from `AppServiceProvider::boot()`:

```php
use XLaravel\Payline\Facades\Payline;

Payline::usePaymentModel(MyPayment::class);
Payline::useTransactionModel(MyTransaction::class);
Payline::useWebhookLogModel(MyWebhookLog::class);
```

These take precedence over the configuration values. Custom models should extend the corresponding Payline model so relationships, casts, and connection handling remain available.

For application-specific callback destinations, bind a custom `CallbackRedirectResolver`.

## Testing

```bash
composer test
```

## License

Payline is open-sourced software licensed under the [MIT license](https://opensource.org/license/MIT).
