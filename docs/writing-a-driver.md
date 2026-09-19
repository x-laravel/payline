# Writing a Driver

- [What a Driver Is](#what-a-driver-is)
- [Contracts](#contracts)
- [A Minimal Driver](#a-minimal-driver)
- [Registering the Driver](#registering-the-driver)
- [Declaring Capabilities](#declaring-capabilities)
- [Mapping Provider Results to Statuses](#mapping-provider-results-to-statuses)
- [Returning a Redirect](#returning-a-redirect)
- [Handling Callbacks](#handling-callbacks)
- [Handling Webhooks](#handling-webhooks)
- [Supporting Reconciliation](#supporting-reconciliation)
- [Testing a Driver](#testing-a-driver)

## What a Driver Is

A driver is a separate Composer package holding one class that speaks the provider's protocol, and a service provider that registers it with Payline. It receives DTOs, returns a `PaymentResponse`, and does nothing else. Persistence, idempotency, ceilings, events and routing stay in Payline.

## Contracts

Implement `Gateway` plus one interface per operation you support. Implement nothing for an operation the provider cannot do; Payline then rejects that call with a `LogicException` instead of recording a failed transaction for an operation that was never possible.

| Contract | Method |
|----------|--------|
| `Gateway` | `getName(): string`, `supportedMethods(): array` |
| `ChargesPayments` | `pay(PaymentRequest $data): PaymentResponse` |
| `AuthorizesPayments` | `authorize(PaymentRequest $data): PaymentResponse` |
| `CapturesPayments` | `capture(CaptureData $data): PaymentResponse` |
| `RefundsPayments` | `refund(RefundData $data): PaymentResponse` |
| `VoidsPayments` | `void(VoidData $data): PaymentResponse` |
| `QueriesPayments` | `queryPayment(PaymentQuery $query): PaymentResponse` |
| `HandlesCallbacks` | `handleCallback(CallbackData $data): PaymentResponse` |
| `HandlesWebhooks` | `verifyWebhook(array $payload, string $signature): bool`, `parseWebhook(array $payload): PaymentResponse` |
| `HandlesRawWebhooks` | `verifyIncomingNotification(IncomingNotification $n): bool`, `parseIncomingNotification(IncomingNotification $n): PaymentResponse` |
| `ProvidesGatewayCapabilities` | `capabilities(): GatewayCapabilities` |

Defining a method without implementing its contract does not work. See [decision 0001](decisions/0001-capability-contracts-are-mandatory.md).

## A Minimal Driver

```php
use XLaravel\Payline\Contracts\ChargesPayments;
use XLaravel\Payline\Contracts\Gateway;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\Enums\PaymentMethod;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;

class MyGateway implements ChargesPayments, Gateway
{
    public function __construct(private readonly array $config) {}

    public function getName(): string
    {
        return 'my-gateway';
    }

    public function supportedMethods(): array
    {
        return [PaymentMethod::CreditCard];
    }

    public function pay(PaymentRequest $data): PaymentResponse
    {
        $result = $this->post('/payments', [
            // ...
        ]);

        return new PaymentResponse(
            status: $result['ok'] ? TransactionStatus::Successful : TransactionStatus::Failed,
            type: TransactionType::Payment,
            gatewayName: $this->getName(),
            gatewayTransactionId: $result['id'] ?? null,
            amount: $data->amount,
            currency: $data->currency,
            errorCode: $result['code'] ?? null,
            errorMessage: $result['message'] ?? null,
        );
    }
}
```

`gatewayName` must equal `getName()` and `type` must equal the operation that was requested. A response that disagrees with either is treated as unusable: the transaction is marked `unknown` and an `UnexpectedGatewayResponseException` is thrown. The same applies to a currency that differs from the transaction.

## Registering the Driver

Register from the driver package's service provider, in `boot()`:

```php
public function boot(): void
{
    $this->app->make('payline')->extend(
        'my-gateway',
        fn ($app, array $config) => new MyGateway($config),
    );
}
```

The closure receives the container and `config('payline.gateways.my-gateway')`, which is an empty array when the key is absent. The instance is cached per driver name for the lifetime of the manager, so keep it free of per request state.

## Declaring Capabilities

Implement `ProvidesGatewayCapabilities` when the provider does not accept everything it is offered. Routing then filters on it before calling the provider:

```php
use XLaravel\Payline\DTOs\GatewayCapabilities;

public function capabilities(): GatewayCapabilities
{
    return new GatewayCapabilities(
        operations: [TransactionType::Payment, TransactionType::Refund],
        methods: [PaymentMethod::CreditCard],
        currencies: ['TRY'],
        installments: [1, 2, 3, 6],
        threeDs: true,
        nonThreeDs: false,
    );
}
```

An empty array means no restriction on that dimension. `partialRefunds`, `webhooks` and `statusQueries` are declarative and are not enforced by routing.

## Mapping Provider Results to Statuses

`TransactionStatus` describes one call, so a refund that succeeded is `successful`, not a refund specific status. Whether the payment ends up partially or fully refunded is derived from the amounts by Payline.

| Provider outcome | Status |
|------------------|--------|
| Charge or capture collected the money | `Successful` |
| Authorization reserved the money | `Authorized` |
| Refund went through | `Successful` |
| Void released the authorization | `Voided` |
| Customer must complete 3D Secure | `Pending` |
| Provider declined | `Failed` |
| Authorization or session lapsed | `Expired` |
| Outcome not determinable | `Unknown` |

Return `Unknown` rather than `Failed` when a request times out or the answer cannot be parsed. `Failed` is a settled state and closes the transaction; `Unknown` leaves it open for reconciliation.

## Returning a Redirect

For 3D Secure, return `Pending` with either a URL or an HTML form:

```php
return new PaymentResponse(
    status: TransactionStatus::Pending,
    type: TransactionType::Payment,
    gatewayName: $this->getName(),
    gatewayTransactionId: $data->reference,
    redirectUrl: $result['redirect_url'],
);
```

Use `redirectForm` instead when the provider answers with a self submitting form. The application decides how to deliver it.

Payline fills `PaymentRequest::$callbackUrl` with its own callback route when the application did not set one, so send the customer back to that URL.

## Handling Callbacks

`handleCallback()` receives the merged query string and post body in `CallbackData::$requestData`, plus the headers and raw body. Verify the provider's hash before trusting anything:

```php
public function handleCallback(CallbackData $data): PaymentResponse
{
    $post = $data->requestData;

    if (! $this->verifyCallbackHash($post)) {
        return new PaymentResponse(
            status: TransactionStatus::Failed,
            type: TransactionType::Payment,
            gatewayName: $this->getName(),
            gatewayTransactionId: $post['OrderId'] ?? null,
            errorCode: 'HASH_MISMATCH',
            errorMessage: 'Security verification failed.',
        );
    }

    // ...
}
```

Set `gatewayTransactionId` or `gatewayOrderId` to the value the provider echoes back. Payline matches on those two, in that order, and cannot record the outcome without one of them.

## Handling Webhooks

Implement `HandlesWebhooks` when the signature covers a normalized payload, and `HandlesRawWebhooks` when it covers the exact request bytes. Implement one, not both.

Set `gatewayEventId` on the response when the provider sends its own event id. Payline uses it to deduplicate retries and falls back to a hash of the raw body, which does not distinguish two genuinely identical events.

Set `eventType` when the provider names the event; it is stored on the log row.

Never return `true` from a verification method that does not verify. A driver without real signature verification should implement neither webhook contract.

## Supporting Reconciliation

`queryPayment()` receives whichever of the provider transaction id, provider order id and merchant reference are known, and returns the current state of that payment. Return the operation type the payment started with, and `Unknown` when the provider itself cannot say.

## Testing a Driver

Test the driver against faked HTTP, not against Payline. Each test asserts the request body sent to the provider and the `PaymentResponse` mapped back:

```php
Http::fake(['*/payments' => Http::response(['ok' => true, 'id' => 'TXN-1'])]);

$response = $this->gateway->pay($this->makePaymentRequest());

$this->assertSame(TransactionStatus::Successful, $response->status);
$this->assertSame('TXN-1', $response->gatewayTransactionId);
```

Assert the contracts as well, including the ones you deliberately do not implement. That test fails when someone adds a method without its contract, or adds a contract without the provider support behind it:

```php
$this->assertInstanceOf(ChargesPayments::class, $this->gateway);
$this->assertNotInstanceOf(AuthorizesPayments::class, $this->gateway);
```
