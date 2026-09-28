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
| `PaymentRefunded` | A refund transaction moved to `successful` |
| `PaymentVoided` | A void transaction moved to `voided` |
| `PaymentFailed` | Any transaction moved to `failed` or `expired` |

| Property | Type |
|----------|------|
| `$payment` | `Payment` |
| `$transaction` | `Transaction` |
| `$response` | `PaymentResponse`, except on `PaymentInitiated` |

`PaymentInitiated` carries `$data`, the `PaymentRequest`, instead of a response.

`PaymentFailed` takes precedence over the operation specific events. A failed capture dispatches `PaymentFailed`, not `PaymentCaptured`.

Every event is chosen from the status that was recorded on the transaction, not from the status the provider reported. The two differ when Payline overrules the provider: a pending answer for a transaction past its deadline is recorded as `expired`, which dispatches `PaymentFailed` while `$response->status` still reads `pending`. Read `$event->transaction->status` in a listener that needs the outcome, and treat `$event->response` as what the provider said.

A refund or a void that did not settle dispatches nothing. An unanswered refund is `unknown`, which is neither a refund nor a failure, and reconciliation settles it later; see [Follow-up Operations](follow-up-operations.md).

## Error Events

| Event | Dispatched when | Properties |
|-------|-----------------|------------|
| `PaymentErrored` | The provider call threw, or returned a response Payline could not accept | `$payment`, `$transaction`, `$exception` |

The transaction has been marked `unknown` by the time the event fires. A listener should treat this as "the outcome is not known yet", not as a failure. Reconciliation resolves these; see [Follow-up Operations](follow-up-operations.md).

A provider Payline could not reach returns an `Unknown` response to the caller, while any other exception is rethrown after the event. Either way the event fires and the transaction is `unknown`, so a listener does not need to tell the two apart.

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
