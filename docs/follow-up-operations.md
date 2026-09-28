# Follow-up Operations

- [Starting Point](#starting-point)
- [Capture](#capture)
- [Capturing in Parts](#capturing-in-parts)
- [Refund](#refund)
- [Void](#void)
- [Reconcile](#reconcile)
- [Reading the Amounts](#reading-the-amounts)
- [Why an Operation Is Rejected](#why-an-operation-is-rejected)

## Starting Point

Every follow-up operation runs against a recorded payment:

```php
use XLaravel\Payline\Facades\Payline;

$operations = Payline::payment($payment);
```

Payline uses the gateway that owns the payment. Passing a different driver throws a `LogicException`, because a provider cannot settle a transaction it never created.

Each operation finds its own parent transaction. Capture looks for the latest `authorized` authorization; void looks for the same, then for the latest `successful` payment; refund looks for the latest `successful` payment or capture. When none exists, the call throws a `LogicException` before anything is recorded.

## Capture

Capturing collects money that an authorization reserved:

```php
$capture = Payline::payment($payment)->capture(
    idempotencyKey: "payment:{$payment->id}:capture:1",
);
```

Without an `amount` the full authorized amount is captured and the payment becomes `successful`.

## Capturing in Parts

Goods that ship separately are often charged separately, so an authorization can be collected over several captures:

```php
Payline::payment($payment)->capture(amount: 4000, idempotencyKey: 'p:1:capture:1');
Payline::payment($payment)->capture(amount: 6000, idempotencyKey: 'p:1:capture:2');
```

While the captured total stays below the payment amount the payment is `partially_captured`. It becomes `successful` when the captures add up to the full amount. See [decision 0004](decisions/0004-capture-is-tracked-against-the-captured-total.md).

Captures cannot exceed the authorized amount in total. `AmountLedger` sums the existing captures under the same authorization and rejects the request that would cross the line, without writing a row.

## Refund

A refund returns money that was collected, so it needs a successful payment or capture to return it from:

```php
$refund = Payline::payment($payment)->refund(
    amount: 2500,
    reason: 'Customer request',
    idempotencyKey: "payment:{$payment->id}:refund:1",
);
```

The amount is required. The currency is taken from the parent transaction, so a refund is always in the currency the money arrived in.

The payment becomes `partially_refunded` while refunds stay below the payment amount and `refunded` when they reach it. A refunded payment accepts no further refunds.

Two ceilings apply. The refund cannot exceed the parent transaction, which is what limits a refund to the amount that particular capture collected, and refunds cannot exceed the payment amount in total. Failed and expired refunds do not consume either ceiling, so a declined refund can be retried for the same amount.

## Void

Voiding releases an authorization that was never captured, or cancels a sale before the provider settles it:

```php
$void = Payline::payment($payment)->void(
    idempotencyKey: "payment:{$payment->id}:void:1",
);
```

The void transaction records the full amount of its parent and the payment becomes `voided`, which is a final state.

A sale can be voided while it is `successful` and has no refund in flight or completed; a failed or expired refund does not count. Whether the provider still accepts the void, typically only before its end of day settlement, is for the provider to answer.

A payment that has been captured, fully or partly, cannot be voided. Use a refund to return money that was already collected.

## Reconcile

A provider call that timed out leaves the transaction `unknown`, which means Payline does not know whether the money moved. Reconciliation asks the provider:

```php
$response = Payline::payment($payment)->reconcile();
```

The driver must implement `QueriesPayments`. Payline builds a `PaymentQuery` from the latest transaction and the payment reference, applies the answer through the same state machine as any other response, and leaves the row untouched when the answer contradicts a settled status.

Run it in bulk for payments that are `pending` or `unknown`:

```shell
php artisan payline:reconcile
php artisan payline:reconcile --gateway=iyzico --limit=50
```

The command processes the least recently updated payments first, defaults to 100 per run, reports failures through Laravel's exception handler, and exits with a failure code when any payment could not be reconciled.

## Reading the Amounts

The money a payment actually collected is the sum of its successful payment and capture transactions, and nothing at all once the payment itself stops counting as successful, such as after a void:

```php
$payment->capturedAmount();
$payment->totalRefunded();
$payment->remainingRefundable();
```

`remainingRefundable()` is the captured amount minus the refunded amount, so an authorization that was only partly captured reports only what was collected.

The same figures aggregate over a payable:

```php
$order->amountPaid();
$order->amountRefunded();
$order->amountNet();
```

## Why an Operation Is Rejected

| Exception | Message | Cause |
|-----------|---------|-------|
| `LogicException` | Payment has no authorized authorization transaction. | Capture on a payment that was charged rather than authorized |
| `LogicException` | Payment has no authorization or sale to void. | Void on a payment that never succeeded |
| `LogicException` | Payment has no successful transaction to refund. | Refund before any money was collected |
| `LogicException` | Follow-up operations must use the payment gateway [x], [y] given. | A driver other than the one that owns the payment |
| `LogicException` | Gateway [x] does not implement [y]. | The driver does not support the operation |
| `InvalidPaymentOperationException` | Only an authorized or partially captured payment can be captured. | The payment is already settled |
| `InvalidPaymentOperationException` | Only a paid payment can be refunded. | The payment holds no outstanding amount |
| `InvalidPaymentOperationException` | Only an authorized payment or an unrefunded sale can be voided. | Money was captured, or part of a sale was refunded |
| `InvalidPaymentOperationException` | Capture amount exceeds the unreserved authorized amount. | Captures would exceed the authorization |
| `InvalidPaymentOperationException` | Refund amount exceeds the unreserved amount of the parent transaction. | Refunds would exceed the parent capture or payment |
| `InvalidPaymentOperationException` | Parent transaction does not belong to the payment. | The parent belongs to another payment |
| `InvalidPaymentOperationException` | Operation currency must match the payment currency. | Currency mismatch |
| `IdempotencyConflictException` | The idempotency key has already been used with a different request. | The same key with a different amount or payload |

A rejected operation writes no transaction row and leaves the payment status unchanged.
