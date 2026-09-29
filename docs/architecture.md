# Architecture

- [Two Records](#two-records)
- [Two State Machines](#two-state-machines)
- [The Outgoing Path](#the-outgoing-path)
- [The Incoming Path](#the-incoming-path)
- [Where Amount Limits Are Enforced](#where-amount-limits-are-enforced)
- [Capability Contracts](#capability-contracts)
- [Class Responsibilities](#class-responsibilities)
- [Model Resolution](#model-resolution)

A payment is rarely a single call. A card is authorized, part of it is captured when the first parcel ships, some of it is refunded, and the provider may report the outcome minutes later over a webhook. Payline models this as one aggregate record with many provider calls underneath it.

## Two Records

A `Payment` is a checkout attempt. It holds the amount the application asked for, the currency, the gateway that owns it, the payable and owner morph links, the card fingerprint that may be stored, and one aggregate status.

A `Transaction` is a single provider call belonging to a payment: the charge, the authorization, each capture, each refund, the void. It holds its own amount, status, attempt number, idempotency key, request fingerprint and the identifiers the provider returned.

Follow-up transactions point at the transaction they derive from through `parent_transaction_id`. A refund belongs to the payment or capture transaction that collected the money, which is what makes a per parent ceiling possible.

## Two State Machines

`TransactionStatus` describes one provider call. A call starts at `initiated`, may sit at `pending` while the customer completes 3D Secure, and settles at `authorized`, `successful`, `failed`, `voided` or `expired`. A settled call never moves again. The exception is `unknown`, which marks a call whose outcome Payline could not determine, and which reconciliation can still resolve in either direction.

`PaymentStatus` describes the aggregate. It adds the states a single call cannot express: `partially_captured` while captures are still below the payment amount, and `partially_refunded` and `refunded` as refunds accumulate.

Both machines answer `canTransitionTo()` on the enum itself, so the rules are testable without a database. `TransactionUpdater` asks before it writes and silently keeps the existing row when the answer is no. A provider that reports a failure after a success therefore cannot overwrite the success.

A refund transaction reports `successful` for its own amount. Whether the payment as a whole is partially or fully refunded is derived from the sum of successful refunds, not from the status of any one of them. Captures work the same way.

## The Outgoing Path

`PendingPayment` is what the application holds after `Payline::via()`, `Payline::for()` or `$model->pay()`. It collects the request fields through fluent methods and builds the `PaymentRequest` when a terminal method is called. `PaymentOperations` is its counterpart for an existing payment, covering capture, refund, void and reconcile.

Both delegate the same sequence. `GatewayResolver` picks the gateway and verifies that it supports the operation. `TransactionRecorder` opens a database transaction, resolves idempotency, applies the amount ceiling and writes the row. `GatewayInvoker` checks the capability contract and calls the provider method. `TransactionRunner` catches what comes back, hands it to `TransactionUpdater`, and dispatches the lifecycle event only when the recorded status actually changed.

`TransactionRunner` treats an exception and an unrecognisable response the same way: the transaction is marked `unknown` rather than failed and a `PaymentErrored` event is dispatched. A timeout is not a decline, and recording it as one would let the application ship goods it was never paid for, or refuse money it already took.

What happens next depends on whose fault it was. A `ConnectionException`, which is Laravel's HTTP client saying it could not reach the provider or gave up waiting, is returned as an `Unknown` response, so the caller reads a status rather than handling an exception for the most common cause of an unknown transaction. Every other exception is rethrown, because it is the gateway's own fault rather than the provider's silence, and hiding it would hide a bug.

## The Incoming Path

A provider reports the result of a 3D Secure flow by redirecting the customer back, and reports later state changes over a webhook. Both arrive at `CallbackHandler`.

For a browser return, `CallbackController` builds a `CallbackData` from the request and calls `CallbackHandler::handle()`, which asks the gateway to interpret it. For a webhook, `IncomingNotificationProcessor` verifies the signature, deduplicates the event against `payline_webhook_logs`, and hands the parsed response to `CallbackHandler::apply()`.

Matching an incoming response to a stored transaction is by provider order id first and provider transaction id second, always scoped to the gateway and the operation type. When nothing matches, a `CallbackUnmatched` event is dispatched and no row is touched.

## Where Amount Limits Are Enforced

`AmountLedger` is the only class that decides whether a capture or a refund fits. It runs inside the same database transaction that creates the row, locks the parent transaction with `lockForUpdate`, and sums the sibling transactions of that type while ignoring failed and expired ones.

For a refund it locks the payment as well and applies a second ceiling against the payment amount. Two concurrent refund requests therefore cannot both pass the check.

`PaymentOperationValidator` verifies state, ownership and currency. It does not look at amounts.

## Capability Contracts

`Gateway` carries only `getName()`. Every operation lives in its own interface: `ChargesPayments`, `AuthorizesPayments`, `CapturesPayments`, `RefundsPayments`, `VoidsPayments`, `QueriesPayments`, `HandlesCallbacks`, `HandlesWebhooks`, `HandlesRawWebhooks`.

`TransactionType` maps each operation to its contract and method name, and `GatewayInvoker` refuses to call a gateway that does not implement the contract. A gateway therefore declares what it supports by implementing interfaces, and routing can ask the same question without calling the provider. See [decision 0001](decisions/0001-capability-contracts-are-mandatory.md).

`ProvidesGatewayCapabilities` is optional and narrower: it filters on currency, installment count, payment method and 3D Secure support.

## Class Responsibilities

| Class | Responsibility |
|-------|----------------|
| `PendingPayment` | Collects request fields, starts a charge or authorization |
| `Concerns\BuildsPaymentRequest` | The fluent setters and the `PaymentRequest` composition |
| `PaymentOperations` | Capture, refund, void and reconcile on a recorded payment |
| `Dispatch\GatewayResolver` | Gateway selection and support checks |
| `Dispatch\GatewayInvoker` | Contract check and the provider call |
| `Routing\GatewayRouter` | Commission ranking from `payline_commission_rates` |
| `BinLookupManager` | Asks every configured BIN provider and merges what they answer |
| `Routing\GatewayPolicyPipeline` | Application supplied routing policies |
| `TransactionRecorder` | Row creation, idempotency and lookups |
| `Payments\AmountLedger` | Capture and refund ceilings |
| `Payments\TransactionRunner` | Call, catch, record, dispatch |
| `Payments\TransactionUpdater` | Locked row updates and payment status sync |
| `StateMachine\PaymentStatusResolver` | Payment status derived from a transaction |
| `Notifications\CallbackHandler` | Matching an incoming response to a transaction |
| `IncomingNotificationProcessor` | Webhook verification, deduplication and logging |
| `PaymentOperationValidator` | State, ownership and currency checks |

## Model Resolution

Every internal class reaches the models through `Payline::paymentModel()`, `Payline::transactionModel()` and `Payline::webhookLogModel()` rather than through `config()` directly. Each resolver prefers a class registered at boot time with `Payline::usePaymentModel()` and falls back to `payline.models.*`.

This keeps model substitution available to a package that boots after Payline, and keeps configuration out of constructors, where a cached configuration would freeze the wrong class.
