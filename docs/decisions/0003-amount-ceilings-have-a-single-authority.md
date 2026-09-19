# 0003 Amount Ceilings Have a Single Authority

## Context

Capturing more than was authorized, or refunding more than was collected, costs the merchant real money. The check is easy to state and easy to get wrong, because it has to hold under concurrency: two refund requests that each pass a check on stale data will both be written.

An earlier arrangement checked amounts in more than one place. The validator compared the requested amount against the parent transaction, and the recorder compared it against the payment. Each check read uncommitted state, neither locked anything, and the two could disagree about what the remaining amount was.

## Decision

`AmountLedger` is the only class that decides whether an amount fits. It runs inside the database transaction that creates the row, locks the parent transaction with `lockForUpdate`, and sums sibling transactions of the same type while ignoring failed and expired ones. For a refund it locks the payment as well and applies a second ceiling against the payment amount.

`PaymentOperationValidator` checks state, ownership and currency, and does not look at amounts.

## Consequences

Two concurrent requests for the same payment serialise on the lock, so the second sees the first one's row and is rejected.

A rejected operation writes nothing, because the check runs before the insert inside the same transaction.

A failed or expired attempt does not consume the ceiling, so a declined refund can be retried for the same amount.

The check costs a locking read per operation, and follow up operations on one payment cannot proceed in parallel. For payment volumes this is the intended trade.

## Status

Accepted.
