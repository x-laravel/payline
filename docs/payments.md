# Payments

- [Making a Model Payable](#making-a-model-payable)
- [Charging a Card](#charging-a-card)
- [Where the Values Come From](#where-the-values-come-from)
- [Chain Methods](#chain-methods)
- [Charging Without a Payable](#charging-without-a-payable)
- [Authorizing Instead of Charging](#authorizing-instead-of-charging)
- [Handling the Response](#handling-the-response)
- [The Gap Between Approved and Failed](#the-gap-between-approved-and-failed)
- [What a Response Carries](#what-a-response-carries)
- [Idempotency](#idempotency)
- [Building the Request Yourself](#building-the-request-yourself)
- [The Raw Gateway](#the-raw-gateway)

## Making a Model Payable

Payline links a payment to the thing being paid for, which lets you ask an order what it has collected. Implement `Payable` on that model and add `HasPayline`:

```php
use Illuminate\Database\Eloquent\Model;
use XLaravel\Payline\Contracts\Payable;
use XLaravel\Payline\Traits\HasPayline;

class Order extends Model implements Payable
{
    use HasPayline;

    public function getPayableReference(): string
    {
        return $this->order_number;
    }

    public function getPayableAmount(): int
    {
        return $this->total;
    }
}
```

Only those two methods are required. `HasPayline` supplies the rest and you override them when the defaults do not fit:

| Method | Default |
|--------|---------|
| `getPayableCurrency()` | `TRY` |
| `getPayableCustomerEmail()` | the model's `email` attribute |
| `getPayableCustomerName()` | the model's `name` attribute |
| `getPayableDescription()` | `null` |

## Charging a Card

`pay()` returns a pending payment that collects the request and sends it when you call `charge()`:

```php
use XLaravel\Payline\DTOs\Card;

$card = new Card(
    holderName: 'Jane Doe',
    number: '4111111111111111',
    expiryMonth: '12',
    expiryYear: '2030',
    cvv: '123',
);

$response = $order->pay('iyzico')
    ->card($card)
    ->customerIp($request->ip())
    ->idempotencyKey((string) str()->uuid())
    ->charge();
```

The facade reaches the same workflow:

```php
use XLaravel\Payline\Facades\Payline;

$response = Payline::for($order)
    ->via('iyzico')
    ->card($card)
    ->charge();
```

Both record a `Payment` and its first `Transaction`, dispatch `PaymentInitiated`, call the provider, and update both rows from the result.

## Where the Values Come From

Reference, amount, currency, customer name, customer email and description are read from the payable. Anything you set on the chain wins over the payable, which is how you charge less than the full total:

```php
$order->pay('iyzico')->card($card)->amount(5000)->charge();
```

The payable is also what links the payment to the model. You never pass the model twice.

## Chain Methods

| Method | Effect |
|--------|--------|
| `reference(string)` | Merchant reference sent to the provider |
| `amount(int)` | Amount in the minor unit |
| `currency(string)` | Three letter ISO code |
| `card(Card)` | Card details |
| `cardToken(string)` | Stored card token instead of a card |
| `saveCard(bool = true)` | Asks the provider to store the card |
| `method(PaymentMethod)` | Payment method, used by capability filtering |
| `installments(int)` | Installment count |
| `threeDs()` / `withoutThreeDs()` | 3D Secure on or off; on by default |
| `customerEmail(string)` | Overrides the payable |
| `customerName(string)` | Overrides the payable |
| `customerPhone(string)` | Customer phone |
| `customerIp(?string)` | Accepts `null` so `$request->ip()` can be passed directly |
| `description(string)` | Overrides the payable |
| `callbackUrl(string)` | Overrides the automatic callback route |
| `basketItems(array)` | `BasketItem` objects |
| `billingAddress(Address)` | Billing address |
| `shippingAddress(Address)` | Shipping address |
| `metadata(array)` | Stored on the payment and the transaction |
| `cardProfile(CardProfile)` | Card family and type, used by commission routing |
| `idempotencyKey(string)` | See [Idempotency](#idempotency) |

`for()` and `by()` set the payable and the owner. `$model->pay()` sets the payable for you when the model implements `Payable`.

## Charging Without a Payable

Without a payable there is nothing to read the required fields from, so supply them:

```php
Payline::via('iyzico')
    ->reference('INV-2026-1')
    ->amount(10000)
    ->card($card)
    ->charge();
```

Charging with neither a payable nor both `reference()` and `amount()` throws a `LogicException`. The currency falls back to `payline.currency`, which defaults to `TRY`.

## Authorizing Instead of Charging

`authorize()` reserves the amount without collecting it. The money is collected later with `capture()`, or released with `void()`:

```php
$response = $order->pay('iyzico')->card($card)->authorize();
```

A driver must implement `AuthorizesPayments` for this to work; see [Follow-up Operations](follow-up-operations.md).

## Handling the Response

`charge()` and `authorize()` return a `PaymentResponse`. With 3D Secure the provider answers with a redirect rather than a result:

```php
if ($response->requiresRedirect()) {
    return $response->redirectForm !== null
        ? response($response->redirectForm)
        : redirect()->away($response->redirectUrl);
}

if ($response->isApproved()) {
    $gatewayTransactionId = $response->gatewayTransactionId;
}

if ($response->isFailure()) {
    logger()->warning('Payment failed', [
        'code' => $response->errorCode,
        'message' => $response->errorMessage,
    ]);
}
```

| Method | True when |
|--------|-----------|
| `isSuccessful()` | status is `successful` |
| `isApproved()` | status is `authorized`, `successful` or `voided` |
| `isPending()` | status is `pending` |
| `isFailure()` | status is `failed` or `expired` |
| `requiresRedirect()` | a redirect URL or an HTML form was returned |

## The Gap Between Approved and Failed

None of those methods covers `unknown`, and that is deliberate: an unknown outcome is neither. Branching on `isFailure()` alone reports an unknown result as a success, which is how an operator ends up retrying an operation the provider already carried out. Decide what the operation does in that case:

```php
if ($response->status === TransactionStatus::Unknown) {
    abort(422, 'The bank did not answer. The operation may have gone through, so do not retry it.');
}

abort_unless($response->isApproved(), 422, $response->errorMessage ?? 'The payment did not go through.');
```

This is the common case rather than an edge case: a provider Payline could not reach returns `Unknown` instead of throwing, so an unreachable provider arrives here rather than in an exception handler. Reconciliation settles the transaction afterwards.

## What a Response Carries

| Property | Type | Meaning |
|----------|------|---------|
| `status` | `TransactionStatus` | Outcome of this one call |
| `type` | `TransactionType` | Operation the answer belongs to |
| `gatewayName` | `string` | Driver that produced it |
| `gatewayTransactionId` | `?string` | Provider's identifier for the transaction |
| `gatewayOrderId` | `?string` | Provider's identifier for the order |
| `gatewayAuthCode` | `?string` | Bank authorization code |
| `gatewayResponseCode` | `?string` | Provider's own result code |
| `gatewayResponseMessage` | `?string` | Provider's own message |
| `amount` | `int` | Confirmed amount in the minor unit; `0` means the request amount stands |
| `currency` | `?string` | `null` when the provider reports none |
| `redirectUrl` / `redirectForm` | `?string` | 3D Secure handoff |
| `errorCode` / `errorMessage` | `?string` | Set on a failure |
| `metadata` | `?array` | Merged into the transaction metadata, provider wins |
| `eventType` / `gatewayEventId` | `?string` | Webhook identifiers |
| `expiresAt` | `?DateTimeInterface` | Deadline for a pending or authorized transaction |
| `refundedAmount` | `?int` | Refunded total on the order, from a status query |
| `voided` | `?bool` | Whether the order was cancelled, from a status query |

`currency`, `refundedAmount` and `voided` are `null` when the provider makes no claim, which is not the same as reporting a currency, zero refunds or no cancellation. A driver never fills them with a guess.

After a redirect the outcome arrives on the callback route; see [Callbacks and Webhooks](callbacks-and-webhooks.md).

## Idempotency

A customer who double clicks, a job that retries, a request that times out after the provider already charged the card: all three send the same operation twice. An idempotency key makes the second one return the first result instead of taking the money again.

```php
$order->pay('iyzico')
    ->card($card)
    ->idempotencyKey("order:{$order->getKey()}:payment")
    ->charge();
```

Repeating the call with the same key and the same payload returns the recorded result without calling the provider. Reusing the key with a different payload throws an `IdempotencyConflictException` and records nothing.

The payload is compared through a SHA-256 fingerprint. For a charge it covers the reference, amount, currency, method, card BIN, card last four, card token, 3D Secure flag and installment count. Fields outside that list, such as the description, do not create a conflict.

Keys are scoped per operation. A payment key and a refund key never collide, and each capture, refund and void takes its own key.

## Building the Request Yourself

`charge()` and `authorize()` also accept a `PaymentRequest` you build, for cases the chain does not cover:

```php
use XLaravel\Payline\DTOs\PaymentRequest;

$response = $order->pay('iyzico')->charge(
    PaymentRequest::fromPayable(payable: $order, card: $card),
);
```

The request is validated in its constructor: an empty reference, an amount of zero or less, a malformed currency, a non positive installment count and an empty idempotency key each throw an `InvalidArgumentException`.

## The Raw Gateway

`Payline::driver('iyzico')` returns the driver itself. Nothing is recorded, validated or deduplicated, and no events are dispatched. Use it while developing a driver, not from application code.
