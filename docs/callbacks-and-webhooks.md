# Callbacks and Webhooks

- [The Two Inbound Paths](#the-two-inbound-paths)
- [Registered Routes](#registered-routes)
- [The Callback Path](#the-callback-path)
- [Redirect Destinations](#redirect-destinations)
- [Leaving the Frame](#leaving-the-frame)
- [The Webhook Path](#the-webhook-path)
- [Deduplication](#deduplication)
- [Payload Storage and Redaction](#payload-storage-and-redaction)
- [Matching a Response to a Transaction](#matching-a-response-to-a-transaction)
- [Driver Contracts](#driver-contracts)

## The Two Inbound Paths

A 3D Secure payment finishes in the customer's browser, not in the request that started it. The provider redirects the customer back with the result, and reports later state changes on a server to server webhook. Payline accepts both and funnels them into the same recording logic.

The difference is trust and shape. A callback arrives in the customer's browser and ends by sending the customer on; a webhook arrives from the provider, is deduplicated, logged, and answered with an empty response.

## Registered Routes

Payline registers two routes when `payline.routes.enabled` is `true`:

```text
GET|POST  {prefix}/callback/{gateway}    name: payline.callback
POST      {prefix}/webhooks/{gateway}    name: payline.webhook
```

The prefix defaults to `payline`. The callback route uses `payline.routes.middleware` with CSRF verification removed, because the request comes from the provider's form post and carries no application token. The webhook route uses the same middleware plus `payline.routes.webhook_middleware`, with the `web` group and CSRF removed, so it neither starts a session nor expects a token. The default webhook middleware is `throttle:60,1`.

Set `payline.routes.enabled` to `false` and register your own routes when you need different paths or middleware.

## The Callback Path

When a payment request carries no callback URL, Payline fills in its own callback route for the gateway that was selected. The provider sends the customer back there.

`CallbackController` builds a `CallbackData` from the query string, the post body, the headers and the raw body, and passes it to `CallbackHandler::handle()`. The handler asks the driver to interpret it through `HandlesCallbacks`, matches the result to a stored transaction, applies it, and returns a `CallbackResult` holding the response and the transaction.

The controller then redirects and flashes three keys to the session:

| Key | Value |
|-----|-------|
| `payline_status` | The transaction status as a string |
| `payline_payment_id` | The payment id, or `null` when nothing matched |
| `payline_transaction_id` | The transaction id, or `null` when nothing matched |

## Redirect Destinations

Where the customer lands is decided by a `CallbackRedirectResolver`. The shipped implementation reads configuration, preferring a per gateway URL and falling back to the global one:

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

A response counts as success when it is approved, which covers `authorized`, `successful` and `voided`.

When the destination depends on the order rather than on the gateway, bind your own resolver:

```php
use XLaravel\Payline\Contracts\CallbackRedirectResolver;

$this->app->bind(CallbackRedirectResolver::class, OrderCallbackRedirects::class);
```

The resolver receives the gateway name and the `CallbackResult`, so it can read the matched transaction and its payment.

## Leaving the Frame

Providers commonly render their 3D Secure step inside an iframe on the checkout page, and the return lands in that same frame. A redirect answered there navigates the frame, so the customer keeps looking at the checkout page with the result hidden inside it.

Payline therefore answers the callback with a small page that moves the top window to the destination. When the callback was not framed the top window is the only window, so the same page behaves exactly like a redirect. A `<noscript>` link covers a browser with scripting off.

Set `payline.routes.callback_breakout` to `false` to answer with a plain redirect instead.

The status, payment id and transaction id are flashed to the session either way. Treat them as a convenience: the callback is a cross site POST, so a browser that withholds the session cookie on it leaves the flash unreachable. Anything that must happen belongs in a listener for the lifecycle events, which fire on the recorded status change regardless of the browser.

## The Webhook Path

`WebhookController` builds an `IncomingNotification` from the request and hands it to `IncomingNotificationProcessor`. The notification keeps the query string, the parsed body, the headers, the raw body and the signature separately, because a provider that signs the raw bytes needs them unmodified.

The signature is read from `X-Webhook-Signature`, falling back to `X-Gateway-Signature`. A driver that signs differently reads the headers itself through `HandlesRawWebhooks`.

The processor verifies the signature first. A failed verification throws a `WebhookSignatureException`, which the controller turns into a `403`, and nothing is logged. Only a verified notification reaches storage.

Once verified, the notification is logged, the parsed response is applied through `CallbackHandler::apply()`, a `WebhookReceived` event is dispatched, and the log is marked processed. When applying it throws, the log records the failure and the exception is rethrown.

## Deduplication

Providers retry webhooks. The log row is keyed on the gateway and the event id, which is the provider's own id when it sends one and a SHA-256 fingerprint of the gateway name and raw body otherwise. The pair is unique in the database.

A notification whose log row already exists and is `processing` or `processed` returns without doing anything. A row left at `received` or `failed` is retried.

## Payload Storage and Redaction

The payload is stored by default. Set `payline.storage.webhook_payload` to `false` to keep the log rows without the body.

Before storage and before the event is dispatched, keys listed in `payline.security.redacted_payload_keys` are replaced with `[REDACTED]`. Matching is case insensitive and recurses into nested arrays. The defaults cover `card_number`, `cardnumber`, `pan`, `cvv`, `cvc` and `security_code`.

## Matching a Response to a Transaction

`CallbackHandler` first rejects a response whose `gatewayName` is not the gateway that received it. It then looks up the transaction by provider order id, and by provider transaction id when that finds nothing. Both lookups are scoped to the gateway and to the operation type of the response.

When nothing matches, a `CallbackUnmatched` event is dispatched and no row is written. This is the normal shape of a notification for a payment that belongs to another system sharing the same provider account.

A matched transaction goes through the same `TransactionUpdater` as an outgoing call, so a webhook cannot move a settled transaction backwards.

## Driver Contracts

| Contract | Use |
|----------|-----|
| `HandlesCallbacks` | Interprets a browser return. Required for 3D Secure |
| `HandlesWebhooks` | Verifies and parses a normalized array payload |
| `HandlesRawWebhooks` | Verifies and parses the raw request, for signatures over the exact bytes |

A driver implements `HandlesWebhooks` or `HandlesRawWebhooks`, not both; `HandlesRawWebhooks` is checked first. A driver that implements neither cannot receive webhooks, and the processor throws a `LogicException` rather than accepting an unverified notification.
