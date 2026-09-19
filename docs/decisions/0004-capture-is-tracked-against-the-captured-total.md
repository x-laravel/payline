# 0004 Capture Is Tracked Against the Captured Total

## Context

Goods that ship in more than one parcel are usually charged in more than one capture. An authorization of 10000 is collected as 4000 when the first parcel leaves and 6000 when the second does.

A payment status derived from a single capture cannot express the state in between. Marking the payment `successful` on the first capture closes it: the validator requires an authorized payment, so the second capture is rejected and the remaining 6000 can never be collected through Payline. The reservation then lapses at the bank after the goods have shipped.

The refund side of the package already had the shape that solves this. A refund transaction reports `successful` for its own amount, and whether the payment is `partially_refunded` or `refunded` is derived from the sum of successful refunds against the payment amount.

## Decision

`PaymentStatus` gains `partially_captured`. Capture status is resolved from the captured total rather than from one transaction: below the payment amount the payment is `partially_captured`, at or above it the payment is `successful`. The validator accepts an authorized or a partially captured payment.

Amounts reported to the application follow the same total. `Payment::capturedAmount()` sums the successful payment and capture transactions, `remainingRefundable()` measures against it, and the payable aggregates do the same.

## Consequences

An authorization can be collected over several captures, and the ceiling still holds because `AmountLedger` measures every capture against the same authorization.

A payment that collected 4000 of an authorized 10000 reports 4000 as paid and 4000 as refundable. Before this, it reported the full 10000 as refundable while only 4000 could actually be refunded.

`partially_captured` counts as successful and as holding an outstanding amount, so a partially captured payment appears in `successfulPayments()` and can be refunded.

The remaining part of a partially captured authorization cannot be released through `void()`, which still requires an authorized payment. Most providers do not accept a void on a partially captured authorization either.

Any code that treated `successful` as the only sign of collected money needs to account for the new status.

## Status

Accepted.
