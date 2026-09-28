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
$order->pay('iyzico')->card($card)->charge();   // named gateway
$order->pay()->card($card)->charge();           // commission routing
Payline::via()->charge();                       // the configured default
```

A named gateway is used as given, after a check that it supports the operation. An unsupported operation throws a `LogicException` rather than silently falling back to another provider.

## The Card Profile

Routing needs to know what kind of card it is looking at. That is a `CardProfile`. Every field is optional, because no BIN lookup service reports all of them: a provider fills what it knows and leaves the rest null.

| Field | Type | Meaning |
|-------|------|---------|
| `bin` | `?string` | The eight digits the profile was resolved from |
| `scheme` | `?CardScheme` | `visa`, `mastercard`, `amex`, `troy`, `discover`, `diners`, `jcb`, `unionpay`, `maestro` |
| `localSchemes` | `CardScheme[]` | Further schemes a co-badged card carries |
| `type` | `?CardType` | `credit`, `debit`, `prepaid`, `charge` |
| `category` | `?CardCategory` | `consumer` or `commercial` |
| `family` | `?string` | Loyalty program such as `bonus`, `axess`, `maximum`, `paraf` |
| `productId` | `?string` | Issuer product code, such as `F` |
| `productType` | `?string` | Product tier, such as `classic` or `platinum` |
| `issuer` | `?string` | Issuing bank |
| `issuerCode` | `?string` | Issuing bank code |
| `issuerCountry` | `?string` | ISO 3166-1 alpha-2 |
| `currency` | `?string` | ISO 4217 currency of the issuing country |
| `prepaid` | `?bool` | Set when a provider reports it apart from `type` |
| `numberLength` | `?int` | Digits the full card number has |
| `source` | `?string` | Name of the provider that answered |
| `raw` | `array` | The provider's untouched payload |

Commission routing reads `family` and `type`. The rest is there for policies and for anyone reading a stored payment later.

```php
use XLaravel\Payline\DTOs\CardProfile;
use XLaravel\Payline\Enums\CardType;

$order->pay()
    ->card($card)
    ->cardProfile(new CardProfile(family: 'bonus', type: CardType::Credit))
    ->charge();
```

Without a profile there is nothing to rank on, and Payline uses the default gateway. A profile whose `family` and `type` are both null matches only the wildcard rates.

Four helpers answer the questions a routing policy usually asks. Neither country method assumes a home country, and both answer `false` when the issuing country is unknown, because an absent country is not evidence either way:

```php
$profile->issuedIn('TR');
$profile->issuedOutside('TR');
$profile->isCoBadged();             // carries more than one scheme
$profile->supports(CardScheme::Troy);
```

`CardScheme`, `CardType` and `CardCategory` each carry a `parse()` that takes a provider's spelling and returns the case or null. `MASTER_CARD`, `MASTERCARD` and `master card` all reach `CardScheme::Mastercard`, so a gateway package never writes its own mapping table.

## BIN Lookup

Supplying the profile by hand is only practical when you already know the card. The usual source is the first eight digits of the card number:

```php
use XLaravel\Payline\BinLookupManager;

$card = $card->resolveProfile(app(BinLookupManager::class));

$order->pay()->card($card)->installments(3)->charge();
```

`resolveProfile()` returns a new `Card` carrying the profile, or the same card when the lookup finds nothing.

The manager registers drivers the way `payline` registers gateways. The default driver is `null`, which resolves nothing, so commission routing does nothing until a provider is registered:

```php
$this->app->make('payline.bin_lookup')->extend(
    'my-lookup',
    fn ($app, array $config) => new MyBinLookupProvider($config),
);
```

List the driver name under `payline.bin_lookup.providers` and put its settings under `payline.bin_lookup.drivers.<name>`. A provider implements `BinLookupProvider`, which receives the eight digit BIN and returns a `CardProfile` or `null`.

Every listed provider is asked and the answers are merged, a field belonging to the first provider that fills it. No single service answers everything: a Turkish one names the card family these rates are keyed on but no country, an international one the reverse.

```php
'bin_lookup' => [
    'providers' => ['hoppa', 'handyapi'],
],
```

A provider that cannot be reached is stepped over rather than taking the lookup down with it. A `ConnectionException` from one leaves the others to answer, and a lookup where none of them answer resolves nothing, which is what an unconfigured lookup does too.

The settings array carries `test_mode` the way a gateway's does, so a provider with a test environment of its own chooses between its addresses without reading the global configuration.

## Commission Rates

Rates live in `payline_commission_rates`, one row per gateway, card family, card type and installment count:

| Column | Meaning |
|--------|---------|
| `gateway` | The gateway name |
| `card_family` | Card family, or `null` for any family |
| `card_type` | Card type, or `null` for any type |
| `installments` | Installment count the rate applies to |
| `rate` | Commission percentage, `decimal(8,4)` |
| `blocking_days` | Days the gateway holds the money before it settles |

The table uses soft deletes, so a rate can be withdrawn without losing the history.

Ranking prices the commission and the wait together: `rate + cost_of_capital * blocking_days / 365`. With `routing.cost_of_capital` left at `0` the wait costs nothing and gateways rank on the commission alone, which is how an installation that never sets it behaves. Set it and a rate that is cheaper on paper can lose to one that settles sooner, which is the point.

## Reading Rates From the Provider

Some providers publish the rates they charge. A gateway that implements `ProvidesCommissionRates` returns them, and one command writes them into the table:

```shell
php artisan payline:sync-rates
php artisan payline:sync-rates --gateway=hoppa --dry-run
```

A row is identified by gateway, card family, card type and installment count, so a second run updates rather than duplicates, and a rate that returns after being deleted is restored. Rows for a gateway that reports nothing, and rows entered by hand, are left alone. `--dry-run` prints what would be written.

A provider that reports no card type leaves the column null, which the matching above treats as a wildcard.

The command fails when a named gateway is not registered or a provider refuses the listing, and skips a gateway that does not implement the contract.

## How a Rate Is Matched

For a given profile and installment count, a row matches when its family equals the card family or is `null`, its type equals the card type or is `null`, and its installment count is exactly equal.

Rows are ordered by how specific they are, then by rate. Each gateway contributes its most specific row, so a gateway's `Bonus` rate is used for a `Bonus` card even when that gateway also has a cheaper wildcard row. The surviving rows are then sorted cheapest first.

Installment matching is exact. A card paying in three installments does not match a row entered for one, so enter a row per installment count you accept.

## Capability Filtering

Payline walks the ranked gateways from cheapest to most expensive and takes the first that passes three checks.

The first is the capability contract: the gateway must implement the interface for the operation. The second applies only to gateways implementing `ProvidesGatewayCapabilities`, and filters on the operation, payment method, currency, installment count and 3D Secure support declared there. An empty list in a `GatewayCapabilities` field means the gateway does not restrict that dimension.

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

Policies run for a named gateway too, not only during commission routing.

## Falling Back

Payline uses the configured default gateway when there is no card profile, when no commission row matches, or when no ranked gateway passes the checks. The default is then checked the same way, and an unsupported operation throws a `LogicException`.

A commission row naming a gateway that is not registered is skipped rather than resolved, so removing a gateway from the configuration does not break payments whose rate rows still mention it.

## Asking Without Paying

To show the customer which provider would be used, or to price a basket, ask without starting a payment:

```php
$gateway = Payline::cheapestFor(new CardProfile(family: 'bonus', type: CardType::Credit), installments: 3);
```

The answer is the cheapest gateway name from the rate table, or `null` when nothing matches. It considers commission only, not capabilities or policies.
