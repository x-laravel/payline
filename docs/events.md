# Events

- [When Events Fire](#when-events-fire)
- [Lifecycle Events](#lifecycle-events)
- [Error Events](#error-events)
- [Inbound Events](#inbound-events)
- [Listening](#listening)

## When Events Fire

`PaymentInitiated` is dispatched when a payment row is created, before the provider is called.

Every other lifecycle event is dispatched only when the recorded transaction status actually changed. A provider that repeats a result, or reports a status the state machine refuses, produces no event. A listener therefore fires once per real change, which makes it safe to send mail or release stock from one.

`PaymentErrored` is the exception: it fires whenever a call throws or returns an unusable response, regardless of whether a status changed.

## Lifecycle Events

All lifecycle events carry the same three properties.

| Event | Dispatched when |
|-------|-----------------|
| `PaymentInitiated` | A new payment and its first transaction were recorded |
| `PaymentPending` | A payment transaction moved to `pending`, usually awaiting 3D Secure |
| `PaymentSucceeded` | A payment transaction moved to `successful` |
| `PaymentAuthorized` | An authorization transaction moved to `authorized` |
| `PaymentCaptured` | A capture transaction moved to `successful` |
| `PaymentRefunded` | A refund transaction changed status |
| `PaymentVoided` | A void transaction changed status |
| `PaymentFailed` | Any transaction moved to `failed` or `expired` |

| Property | Type |
|----------|------|
| `$payment` | `Payment` |
| `$transaction` | `Transaction` |
| `$response` | `PaymentResponse`, except on `PaymentInitiated` |

`PaymentInitiated` carries `$data`, the `PaymentRequest`, instead of a response.

`PaymentFailed` takes precedence over the operation specific events. A failed capture dispatches `PaymentFailed`, not `PaymentCaptured`.

`PaymentRefunded` and `PaymentVoided` are dispatched for every status change on those transaction types that is not a failure, including a move to `pending`. Check `$response->isSuccessful()` in the listener when only a settled refund should count.

## Error Events

| Event | Dispatched when | Properties |
|-------|-----------------|------------|
| `PaymentErrored` | The provider call threw, or returned a response Payline could not accept | `$payment`, `$transaction`, `$exception` |

The transaction has been marked `unknown` by the time the event fires, and the original exception is rethrown afterwards. A listener should treat this as "the outcome is not known yet", not as a failure. Reconciliation resolves these; see [Follow-up Operations](follow-up-operations.md).

## Inbound Events

| Event | Dispatched when | Properties |
|-------|-----------------|------------|
| `WebhookReceived` | A verified webhook was applied successfully | `$gateway`, `$response`, `$payload` |
| `CallbackUnmatched` | An incoming response matched no stored transaction | `$gateway`, `$response` |

`$payload` is the stored payload, after redaction, and is an empty array when `payline.storage.webhook_payload` is `false`.

`CallbackUnmatched` fires for both callbacks and webhooks. It is normal when the provider account is shared with another system, and worth alerting on otherwise.

## Listening

```php
use Illuminate\Support\Facades\Event;
use XLaravel\Payline\Events\PaymentSucceeded;

Event::listen(function (PaymentSucceeded $event) {
    $order = $event->payment->payable;

    $order?->markPaid($event->payment->capturedAmount());
});
```

`$payment->payable` is the model the payment was created for, and is `null` when the payment was started without one.

Listeners run inside the request that made the payment. Queue anything slow, and remember that the payment row is already committed when the event fires.
