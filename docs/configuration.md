# Configuration

- [Publishing](#publishing)
- [Gateways](#gateways)
- [Test Mode](#test-mode)
- [Currency](#currency)
- [Routes](#routes)
- [Callback URLs](#callback-urls)
- [Routing](#routing)
- [BIN Lookup](#bin-lookup)
- [Transactions](#transactions)
- [Database](#database)
- [Models](#models)
- [Storage](#storage)
- [Security](#security)
- [Environment Variables](#environment-variables)

## Publishing

```shell
php artisan vendor:publish --tag=payline-config
```

The file is merged, so an unpublished configuration uses the package defaults and a published one may omit any key it does not change.

## Gateways

| Key | Type | Default | Meaning |
|-----|------|---------|---------|
| `default` | `?string` | `env('PAYLINE_GATEWAY')` | Gateway used when none is named |
| `gateways` | `array` | one `iyzico` example | Per gateway settings, keyed by gateway name |

Each entry under `gateways` is passed to the gateway factory as its configuration array. Its contents are defined by the gateway package. Resolving a gateway with no `default` set throws a `RuntimeException`.

## Test Mode

| Key | Type | Default | Meaning |
|-----|------|---------|---------|
| `test_mode` | `bool` | `env('PAYLINE_TEST_MODE', false)` | Sends every gateway to its provider's test environment |

The flag reaches gateway and BIN lookup factories as `test_mode` in the configuration array they receive. A gateway package ships both of its provider's addresses and chooses between them; Payline knows no provider address of its own.

```php
Payline::testMode(); // bool
```

A gateway entry may declare its own `test_mode`, which wins over the global flag. That leaves a single provider in its test environment while the rest stay live, which is what certification for a new bank usually needs.

```php
'test_mode' => false,

'gateways' => [
    'qnb' => [
        'test_mode' => true,
    ],
],
```

A `base_url` given in a gateway entry wins over both addresses the package ships.

The flag carries no credentials. A test environment has its own merchant identifiers, keys and passwords, so turning the flag off also means replacing those values.

## Currency

| Key | Type | Default | Meaning |
|-----|------|---------|---------|
| `currency` | `string` | `env('PAYLINE_CURRENCY', 'TRY')` | Currency for a charge that has neither a payable nor an explicit `currency()` |

## Routes

| Key | Type | Default | Meaning |
|-----|------|---------|---------|
| `routes.enabled` | `bool` | `true` | Registers the callback and webhook routes |
| `routes.prefix` | `string` | `payline` | URI prefix for both routes |
| `routes.middleware` | `array` | `['web']` | Middleware for both routes |
| `routes.webhook_middleware` | `array` | `['throttle:60,1']` | Additional middleware for the webhook route |
| `routes.callback_response` | `string` | `breakout` | How the callback answers the browser: `breakout`, `redirect` or `view` |
| `routes.callback_views.success` | `string` | `payline::callback` | View rendered after an approved callback in `view` mode |
| `routes.callback_views.failure` | `string` | `payline::callback` | View rendered after any other callback in `view` mode |

CSRF verification is removed from both routes. The `web` group is removed from the webhook route.

The shipped view can be published with `php artisan vendor:publish --tag=payline-views`.

## Callback URLs

| Key | Type | Default | Meaning |
|-----|------|---------|---------|
| `callback_success_url` | `string` | `env('PAYLINE_CALLBACK_SUCCESS_URL', '/')` | Destination after an approved callback |
| `callback_failure_url` | `string` | `env('PAYLINE_CALLBACK_FAILURE_URL', '/')` | Destination after any other callback |

A gateway entry may override both with its own `callback_success_url` and `callback_failure_url`. Per gateway values win.

## Routing

| Key | Type | Default | Meaning |
|-----|------|---------|---------|
| `routing.policies` | `array` | `[]` | Classes implementing `GatewayRoutingPolicy`, applied to every gateway choice |

Policies are resolved from the container. A class that does not implement the contract throws a `LogicException`.

## BIN Lookup

| Key | Type | Default | Meaning |
|-----|------|---------|---------|
| `bin_lookup.default` | `string` | `env('PAYLINE_BIN_LOOKUP_DRIVER', 'null')` | BIN lookup driver name |
| `bin_lookup.drivers` | `array` | `[]` | Per driver settings, keyed by driver name |

The `null` driver resolves no card profile, which leaves commission routing inactive.

## Transactions

| Key | Type | Default | Meaning |
|-----|------|---------|---------|
| `transactions.pending_ttl` | `?int` | `env('PAYLINE_PENDING_TTL', 60)` | Minutes a pending transaction may wait for the customer, when the gateway names no deadline of its own |

A customer who abandons a 3D Secure page leaves the transaction waiting, and providers answer a status query for such an order with "not completed", which is indistinguishable from a customer who is still on the page. Payline settles the difference with time: a pending answer for a transaction that is past its deadline is recorded as `expired` instead.

The deadline is the `expiresAt` the gateway returned when it started the payment, and this TTL counted from the transaction's creation when it returned none. Set the key to `null` to let pending transactions wait indefinitely.

An answer that actually settles the transaction always wins over the deadline, so a customer who completes the payment late is still recorded as paid.

## Database

| Key | Type | Default | Meaning |
|-----|------|---------|---------|
| `database.connection` | `?string` | `env('PAYLINE_DB_CONNECTION', env('DB_CONNECTION', 'sqlite'))` | Connection for the Payline tables and the published migrations |

## Models

| Key | Type | Default |
|-----|------|---------|
| `models.payment` | `class-string` | `XLaravel\Payline\Models\Payment` |
| `models.transaction` | `class-string` | `XLaravel\Payline\Models\Transaction` |
| `models.webhook_log` | `class-string` | `XLaravel\Payline\Models\WebhookLog` |
| `models.commission_rate` | `class-string` | `XLaravel\Payline\Models\CommissionRate` |

All four models can also be set at boot time, which takes precedence over configuration:

```php
use XLaravel\Payline\Facades\Payline;

Payline::usePaymentModel(MyPayment::class);
Payline::useTransactionModel(MyTransaction::class);
Payline::useWebhookLogModel(MyWebhookLog::class);
Payline::useCommissionRateModel(MyCommissionRate::class);
```

A replacement extends the Payline model, so relationships, casts and connection handling stay intact.

## Storage

| Key | Type | Default | Meaning |
|-----|------|---------|---------|
| `storage.card_details` | `bool` | `true` | Stores the card BIN and last four digits on the payment |
| `storage.card_holder_name` | `bool` | `true` | Stores the cardholder name on the payment |
| `storage.webhook_payload` | `bool` | `true` | Stores the webhook payload on the log row |

Payline never stores a full card number or a CVV. `Card` masks both in its debug output and its JSON representation, and its number and CVV parameters are marked `#[SensitiveParameter]` so they do not appear in stack traces.

## Security

| Key | Type | Default | Meaning |
|-----|------|---------|---------|
| `security.redacted_payload_keys` | `array` | `card_number`, `cardnumber`, `pan`, `cvv`, `cvc`, `security_code` | Webhook payload keys replaced with `[REDACTED]` |

Matching is case insensitive and recurses into nested arrays. Redaction happens before the payload is stored and before `WebhookReceived` is dispatched.

## Environment Variables

| Variable | Used by |
|----------|---------|
| `PAYLINE_GATEWAY` | `default` |
| `PAYLINE_TEST_MODE` | `test_mode` |
| `PAYLINE_CURRENCY` | `currency` |
| `PAYLINE_DB_CONNECTION` | `database.connection` |
| `PAYLINE_BIN_LOOKUP_DRIVER` | `bin_lookup.default` |
| `PAYLINE_PENDING_TTL` | `transactions.pending_ttl` |
| `PAYLINE_CALLBACK_SUCCESS_URL` | `callback_success_url` |
| `PAYLINE_CALLBACK_FAILURE_URL` | `callback_failure_url` |

Gateway packages define their own variables under their gateway entry.
