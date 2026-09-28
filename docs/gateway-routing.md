# Gateway Routing

- [Three Ways to Choose a Gateway](#three-ways-to-choose-a-gateway)
- [The Card Profile](#the-card-profile)
- [BIN Lookup](#bin-lookup)
- [Commission Rates](#commission-rates)
- [Reading Rates From the Provider](#reading-rates-from-the-provider)
- [How a Rate Is Matched](#how-a-rate-is-matched)
- [Capability Filtering](#capability-filtering)
- [Routing Policies](#routing-policies)
- [Falling Back](#falling-back)
- [Asking Without Paying](#asking-without-paying)

## Three Ways to Choose a Gateway

Merchants often hold accounts with several providers whose commission differs by card. Payline can pick the cheapest one that is able to take the payment.

```php
$order->pay('iyzico')->card($card)->charge();   // named driver
$order->pay()->card($card)->charge();           // commission routing
Payline::via()->charge();                       // the configured default
```

A named driver is used as given, after a check that it supports the operation. An unsupported operation throws a `LogicException` rather than silently falling back to another provider.

## The Card Profile

Routing needs to know what kind of card it is looking at. That is a `CardProfile`: a card family such as `Bonus` or `Maximum`, and a `CardType` of `credit`, `debit` or `foreign_credit`.

Payline reads the profile from the card, then from the request:

```php
use XLaravel\Payline\DTOs\CardProfile;
use XLaravel\Payline\Enums\CardType;

$order->pay()
    ->card($card)
    ->cardProfile(new CardProfile('Bonus', CardType::Credit))
    ->charge();
```

Without a profile there is nothing to rank on, and Payline uses the default gateway.

## BIN Lookup

Supplying the profile by hand is only practical when you already know the card. The usual source is the first eight digits of the card number:

```php
use XLaravel\Payline\BinLookupManager;

$card = $card->resolveProfile(app(BinLookupManager::class));

$order->pay()->card($card)->installments(3)->charge();
```

`resolveProfile()` returns a new `Card` carrying the profile, or the same card when the lookup finds nothing.

The manager is a driver manager like `payline`. The default driver is `null`, which resolves nothing, so commission routing does nothing until a provider is registered:

```php
$this->app->make('payline.bin_lookup')->extend(
    'my-lookup',
    fn ($app, array $config) => new MyBinLookupProvider($config),
);
```

Point `payline.bin_lookup.default` at the driver name and put its settings under `payline.bin_lookup.drivers.<name>`. A provider implements `BinLookupProvider`, which receives the eight digit BIN and returns a `CardProfile` or `null`.

## Commission Rates

Rates live in `payline_commission_rates`, one row per gateway, card family, card type and installment count:

| Column | Meaning |
|--------|---------|
| `gateway` | The driver name |
| `card_family` | Card family, or `null` for any family |
| `card_type` | Card type, or `null` for any type |
| `installments` | Installment count the rate applies to |
| `rate` | Commission percentage, `decimal(8,4)` |
| `blocking_days` | Settlement delay in days, recorded but not used in ranking |

The table uses soft deletes, so a rate can be withdrawn without losing the history.

## Reading Rates From the Provider

Some providers publish the rates they charge. A driver that implements `ProvidesCommissionRates` returns them, and one command writes them into the table:

```shell
php artisan payline:sync-rates
php artisan payline:sync-rates --gateway=hoppa --dry-run
```

A row is identified by gateway, card family, card type and installment count, so a second run updates rather than duplicates, and a rate that returns after being deleted is restored. Rows for a gateway that reports nothing, and rows entered by hand, are left alone. `--dry-run` prints what would be written.

A provider that reports no card type leaves the column null, which the matching above treats as a wildcard.

The command fails when a named gateway is not registered or a provider refuses the listing, and skips a driver that does not implement the contract.

## How a Rate Is Matched

For a given profile and installment count, a row matches when its family equals the card family or is `null`, its type equals the card type or is `null`, and its installment count is exactly equal.

Rows are ordered by how specific they are, then by rate. Each gateway contributes its most specific row, so a gateway's `Bonus` rate is used for a `Bonus` card even when that gateway also has a cheaper wildcard row. The surviving rows are then sorted cheapest first.

Installment matching is exact. A card paying in three installments does not match a row entered for one, so enter a row per installment count you accept.

## Capability Filtering

Payline walks the ranked gateways from cheapest to most expensive and takes the first that passes three checks.

The first is the capability contract: the driver must implement the interface for the operation. The second applies only to drivers implementing `ProvidesGatewayCapabilities`, and filters on the operation, payment method, currency, installment count and 3D Secure support declared there. An empty list in a `GatewayCapabilities` field means the driver does not restrict that dimension.

The third is the policy pipeline.

## Routing Policies

A policy is the place for a rule that Payline cannot know, such as a per provider daily limit or a merchant agreement:

```php
use XLaravel\Payline\Contracts\Gateway;
use XLaravel\Payline\Contracts\GatewayRoutingPolicy;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\Enums\TransactionType;

class CurrencyPolicy implements GatewayRoutingPolicy
{
    public function allows(
        Gateway $gateway,
        PaymentRequest $request,
        TransactionType $operation,
    ): bool {
        return $request->currency === 'TRY';
    }
}
```

```php
'routing' => [
    'policies' => [CurrencyPolicy::class],
],
```

Policies are resolved from the container, so they may take dependencies. Every policy must allow the gateway; the first refusal moves routing to the next candidate. A configured class that does not implement the contract throws a `LogicException`.

Policies run for a named driver too, not only during commission routing.

## Falling Back

Payline uses the configured default gateway when there is no card profile, when no commission row matches, or when no ranked gateway passes the checks. The default is then checked the same way, and an unsupported operation throws a `LogicException`.

A commission row naming a gateway that is not registered is skipped rather than resolved, so removing a driver from the configuration does not break payments whose rate rows still mention it.

## Asking Without Paying

To show the customer which provider would be used, or to price a basket, ask without starting a payment:

```php
$gateway = Payline::cheapestFor(new CardProfile('Bonus', CardType::Credit), installments: 3);
```

The answer is the cheapest gateway name from the rate table, or `null` when nothing matches. It considers commission only, not capabilities or policies.
