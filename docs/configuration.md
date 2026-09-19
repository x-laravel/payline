# Configuration

- [Publishing](#publishing)
- [Gateways](#gateways)
- [Currency](#currency)
- [Routes](#routes)
- [Callback URLs](#callback-urls)
- [Routing](#routing)
- [BIN Lookup](#bin-lookup)
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
| `default` | `?string` | `env('PAYLINE_DRIVER')` | Driver used when none is named |
| `gateways` | `array` | one `iyzico` example | Per driver settings, keyed by driver name |

Each entry under `gateways` is passed to the driver factory as its configuration array. Its contents are defined by the driver package. Resolving a gateway with no `default` set throws a `RuntimeException`.

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

CSRF verification is removed from both routes. The `web` group is removed from the webhook route.

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

Payment, transaction and webhook log models can also be set at boot time, which takes precedence over configuration:

```php
use XLaravel\Payline\Facades\Payline;

Payline::usePaymentModel(MyPayment::class);
Payline::useTransactionModel(MyTransaction::class);
Payline::useWebhookLogModel(MyWebhookLog::class);
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
| `PAYLINE_DRIVER` | `default` |
| `PAYLINE_CURRENCY` | `currency` |
| `PAYLINE_DB_CONNECTION` | `database.connection` |
| `PAYLINE_BIN_LOOKUP_DRIVER` | `bin_lookup.default` |
| `PAYLINE_CALLBACK_SUCCESS_URL` | `callback_success_url` |
| `PAYLINE_CALLBACK_FAILURE_URL` | `callback_failure_url` |

Driver packages define their own variables under their gateway entry.
