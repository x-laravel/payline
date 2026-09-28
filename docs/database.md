# Database

- [Connection](#connection)
- [payline_payments](#payline_payments)
- [payline_transactions](#payline_transactions)
- [payline_webhook_logs](#payline_webhook_logs)
- [payline_commission_rates](#payline_commission_rates)
- [Payment Statuses](#payment-statuses)
- [Transaction Statuses](#transaction-statuses)
- [Transaction Types](#transaction-types)
- [Webhook Statuses](#webhook-statuses)
- [Other Enumerations](#other-enumerations)

## Connection

All four tables and their migrations read `payline.database.connection`. Primary keys are ULIDs.

The migrations are published rather than loaded from the package, so the schema is the application's to edit. See [decision 0002](decisions/0002-migrations-are-published-not-loaded.md).

## payline_payments

The aggregate record of a checkout attempt.

| Column | Type | Notes |
|--------|------|-------|
| `id` | `ulid` | Primary key |
| `gateway` | `string(50)` | Driver name that owns the payment |
| `initial_type` | `string(30)` | `payment` or `authorization` |
| `status` | `string(30)` | Defaults to `initiated` |
| `idempotency_key` | `string`, nullable | Supplied by the application |
| `request_hash` | `char(64)` | SHA-256 fingerprint of the request |
| `amount` | `unsignedBigInteger` | Requested amount in the minor unit |
| `currency` | `char(3)` | Defaults to `TRY` |
| `card_bin` | `char(8)`, nullable | First eight digits |
| `card_last_four` | `char(4)`, nullable | Last four digits |
| `card_holder_name` | `string`, nullable | |
| `payable_type` / `payable_id` | nullable morph | The thing being paid for |
| `owner_type` / `owner_id` | nullable morph | Who is paying |
| `reference` | `string`, nullable, indexed | Merchant reference |
| `description` | `text`, nullable | |
| `metadata` | `json`, nullable | |
| `completed_at` | `timestamp`, nullable | Set when the status becomes final |

Indexes: `(gateway, status)`, `(payable_type, payable_id, status)`, `(owner_type, owner_id, status)`. Unique on `(gateway, initial_type, idempotency_key)`.

## payline_transactions

One provider call.

| Column | Type | Notes |
|--------|------|-------|
| `id` | `ulid` | Primary key |
| `payment_id` | `foreignUlid` | Cascades on delete |
| `type` | `string(30)` | See [Transaction Types](#transaction-types) |
| `status` | `string(30)` | Defaults to `initiated` |
| `amount` | `unsignedBigInteger` | Narrowed to the provider confirmed amount when it is lower |
| `currency` | `char(3)` | Defaults to `TRY` |
| `attempt` | `unsignedSmallInteger` | Incremented per type within the payment |
| `idempotency_key` | `string`, nullable | |
| `request_hash` | `char(64)` | SHA-256 fingerprint of the operation |
| `gateway_transaction_id` | `string`, nullable, indexed | Provider identifier |
| `gateway_order_id` | `string`, nullable, indexed | Provider order identifier |
| `gateway_auth_code` | `string(100)`, nullable | |
| `gateway_response_code` | `string(50)`, nullable | |
| `gateway_response_message` | `text`, nullable | |
| `error_code` | `string(100)`, nullable | |
| `error_message` | `text`, nullable | |
| `redirect_url` | `text`, nullable | |
| `metadata` | `json`, nullable | |
| `parent_transaction_id` | `ulid`, nullable | Self reference, null on delete |
| `expires_at` | `timestamp`, nullable | Set from the driver's `expiresAt`, typically an authorization window |
| `completed_at` | `timestamp`, nullable | Set when the status becomes final |

Indexes: `(payment_id, type, status)`. Unique on `(payment_id, type, idempotency_key)`.

A capture, refund or void points at the transaction it derives from through `parent_transaction_id`. That link is what the amount ceilings are measured against.

## payline_webhook_logs

One provider notification.

| Column | Type | Notes |
|--------|------|-------|
| `id` | `ulid` | Primary key |
| `gateway` | `string(50)` | |
| `event_type` | `string`, nullable | Provider event name |
| `gateway_event_id` | `string` | Provider event id, or a fingerprint of the raw body |
| `payload_hash` | `char(64)` | SHA-256 of the gateway name and raw body |
| `payload` | `json` | Empty when `payline.storage.webhook_payload` is `false` |
| `status` | `string(30)` | Defaults to `received` |
| `exception` | `text`, nullable | Message from a failed processing attempt |
| `processed_at` | `timestamp`, nullable | |

Indexes: `(gateway, status)`, `(gateway, payload_hash)`. Unique on `(gateway, gateway_event_id)`, which is what makes retries idempotent.

## payline_commission_rates

Provider pricing used by commission routing.

| Column | Type | Notes |
|--------|------|-------|
| `id` | `ulid` | Primary key |
| `gateway` | `string(50)` | Driver name |
| `card_family` | `string(50)`, nullable | Null matches any family |
| `card_type` | `string(30)`, nullable | Null matches any type |
| `installments` | `unsignedTinyInteger` | Matched exactly, defaults to 1 |
| `rate` | `decimal(8,4)` | Commission percentage |
| `blocking_days` | `unsignedTinyInteger`, nullable | Recorded but not used in ranking |

Uses soft deletes. Index: `(gateway, card_family, card_type, installments)`.

## Payment Statuses

| Value | Meaning |
|-------|---------|
| `initiated` | Recorded, provider not answered yet |
| `pending` | Awaiting the customer, usually 3D Secure |
| `authorized` | Amount reserved, nothing collected |
| `partially_captured` | Captures so far are below the payment amount |
| `successful` | Collected in full |
| `partially_refunded` | Refunds so far are below the payment amount |
| `refunded` | Refunded in full |
| `voided` | Authorization released |
| `failed` | Declined |
| `expired` | Lapsed before settling |
| `unknown` | Outcome not determinable |

The `Paid` case has the string value `successful`.

Final statuses are `successful`, `refunded`, `voided`, `failed` and `expired`. A payment counts as successful when it is `partially_captured`, `successful`, `partially_refunded` or `refunded`, and as holding an outstanding amount when it is `partially_captured`, `successful` or `partially_refunded`. Reconciliation targets `pending` and `unknown`.

## Transaction Statuses

| Value | Meaning |
|-------|---------|
| `initiated` | Row created, provider not answered yet |
| `pending` | Awaiting the customer |
| `authorized` | Amount reserved |
| `successful` | Operation completed, including a completed refund |
| `failed` | Declined |
| `voided` | Authorization released |
| `expired` | Lapsed |
| `unknown` | Outcome not determinable |

`authorized`, `successful`, `failed` and `voided` are final and cannot change again. `initiated` and `unknown` can move anywhere; `pending` can settle into any of the others. `expired` is Payline's own verdict that a deadline passed rather than the provider's, so it still yields to a provider that later reports `authorized` or `successful`.

There is no refund specific transaction status. A refund that went through is `successful` for its own amount.

## Transaction Types

| Value | Contract | Driver method |
|-------|----------|---------------|
| `payment` | `ChargesPayments` | `pay` |
| `authorization` | `AuthorizesPayments` | `authorize` |
| `capture` | `CapturesPayments` | `capture` |
| `refund` | `RefundsPayments` | `refund` |
| `void` | `VoidsPayments` | `void` |

## Webhook Statuses

| Value | Meaning |
|-------|---------|
| `received` | Logged, not processed yet |
| `processing` | Being applied |
| `processed` | Applied |
| `failed` | Applying threw |

A notification arriving while its log row is `processing` or `processed` is ignored. One whose row is `received` or `failed` is retried.

## Other Enumerations

`PaymentMethod`: `credit_card`, `debit_card`, `bank_transfer`, `token`.

`CardType`: `credit`, `debit`, `foreign_credit`.
