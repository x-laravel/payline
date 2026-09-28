<?php

namespace XLaravel\Payline\Tests\Feature\Routing;

use XLaravel\Payline\DTOs\CardProfile;
use XLaravel\Payline\Enums\CardType;
use XLaravel\Payline\Models\CommissionRate;
use XLaravel\Payline\Routing\GatewayRouter;
use XLaravel\Payline\Tests\TestCase;

class GatewayRouterTest extends TestCase
{
    public function test_returns_cheapest_gateway(): void
    {
        CommissionRate::create(['gateway' => 'hoppa', 'installments' => 1, 'rate' => 2.03]);
        CommissionRate::create(['gateway' => 'iyzico', 'installments' => 1, 'rate' => 2.92]);

        $cheapest = app(GatewayRouter::class)->cheapestFor(new CardProfile(family: 'Bonus', type: CardType::Credit));

        $this->assertSame('hoppa', $cheapest);
    }

    public function test_returns_null_when_no_rates_exist(): void
    {
        $cheapest = app(GatewayRouter::class)->cheapestFor(new CardProfile(family: 'Bonus', type: CardType::Credit));

        $this->assertNull($cheapest);
    }

    public function test_rankedFor_returns_gateways_sorted_ascending_by_rate(): void
    {
        CommissionRate::create(['gateway' => 'iyzico', 'installments' => 1, 'rate' => 2.92]);
        CommissionRate::create(['gateway' => 'hoppa', 'installments' => 1, 'rate' => 2.03]);
        CommissionRate::create(['gateway' => 'qnb', 'installments' => 1, 'rate' => 2.50]);

        $ranked = app(GatewayRouter::class)->rankedFor(new CardProfile(family: 'Bonus', type: CardType::Credit));

        $this->assertSame(['hoppa', 'qnb', 'iyzico'], array_keys($ranked));
    }

    public function test_specific_card_family_rate_takes_priority_over_wildcard_for_same_gateway(): void
    {
        // qnb has a wildcard rate (1.00) and a Bonus-specific rate (3.00)
        // For a Bonus card, the specific rate should be used, not the wildcard
        CommissionRate::create(['gateway' => 'qnb', 'card_family' => null, 'installments' => 1, 'rate' => 1.00]);
        CommissionRate::create(['gateway' => 'qnb', 'card_family' => 'Bonus', 'installments' => 1, 'rate' => 3.00]);

        $ranked = app(GatewayRouter::class)->rankedFor(new CardProfile(family: 'Bonus', type: CardType::Credit));

        $this->assertSame(3.00, $ranked['qnb']);
    }

    public function test_the_days_a_gateway_holds_the_money_are_ignored_until_capital_is_priced(): void
    {
        CommissionRate::create(['gateway' => 'hoppa', 'installments' => 1, 'rate' => 2.25, 'blocking_days' => 14]);
        CommissionRate::create(['gateway' => 'qnb', 'installments' => 1, 'rate' => 2.91, 'blocking_days' => 5]);

        $ranked = app(GatewayRouter::class)->rankedFor(new CardProfile(family: 'bonus', type: CardType::Credit));

        $this->assertSame(['hoppa', 'qnb'], array_keys($ranked));
        $this->assertSame(2.25, $ranked['hoppa']);
    }

    public function test_a_cheaper_rate_loses_to_a_shorter_hold_once_capital_is_priced(): void
    {
        config(['payline.routing.cost_of_capital' => 40]);

        CommissionRate::create(['gateway' => 'hoppa', 'installments' => 1, 'rate' => 2.25, 'blocking_days' => 14]);
        CommissionRate::create(['gateway' => 'qnb', 'installments' => 1, 'rate' => 2.91, 'blocking_days' => 5]);

        $ranked = app(GatewayRouter::class)->rankedFor(new CardProfile(family: 'bonus', type: CardType::Credit));

        $this->assertSame(['qnb', 'hoppa'], array_keys($ranked));
        $this->assertEqualsWithDelta(3.4579, $ranked['qnb'], 0.0001);
        $this->assertEqualsWithDelta(3.7842, $ranked['hoppa'], 0.0001);
    }

    public function test_a_rate_that_names_no_holding_period_is_priced_on_its_rate_alone(): void
    {
        config(['payline.routing.cost_of_capital' => 40]);

        CommissionRate::create(['gateway' => 'hoppa', 'installments' => 1, 'rate' => 2.25]);

        $ranked = app(GatewayRouter::class)->rankedFor(new CardProfile(family: 'bonus', type: CardType::Credit));

        $this->assertSame(2.25, $ranked['hoppa']);
    }

    public function test_a_profile_without_a_family_or_type_only_matches_wildcard_rates(): void
    {
        CommissionRate::create(['gateway' => 'hoppa', 'card_family' => null, 'card_type' => null, 'installments' => 1, 'rate' => 2.50]);
        CommissionRate::create(['gateway' => 'qnb', 'card_family' => 'Bonus', 'card_type' => 'credit', 'installments' => 1, 'rate' => 1.00]);

        $ranked = app(GatewayRouter::class)->rankedFor(new CardProfile(issuerCountry: 'DE'));

        $this->assertSame(['hoppa' => 2.50], $ranked);
    }

    public function test_wildcard_rate_applies_when_no_specific_match(): void
    {
        CommissionRate::create(['gateway' => 'hoppa', 'card_family' => null, 'installments' => 1, 'rate' => 2.50]);

        $ranked = app(GatewayRouter::class)->rankedFor(new CardProfile(family: 'Axess', type: CardType::Credit));

        $this->assertArrayHasKey('hoppa', $ranked);
        $this->assertSame(2.50, $ranked['hoppa']);
    }

    public function test_soft_deleted_rates_are_excluded(): void
    {
        CommissionRate::create(['gateway' => 'hoppa', 'installments' => 1, 'rate' => 2.03]);
        CommissionRate::create(['gateway' => 'iyzico', 'installments' => 1, 'rate' => 2.92])->delete();

        $ranked = app(GatewayRouter::class)->rankedFor(new CardProfile(family: 'Bonus', type: CardType::Credit));

        $this->assertArrayHasKey('hoppa', $ranked);
        $this->assertArrayNotHasKey('iyzico', $ranked);
    }

    public function test_installment_count_is_respected(): void
    {
        CommissionRate::create(['gateway' => 'hoppa', 'installments' => 1, 'rate' => 2.03]);
        CommissionRate::create(['gateway' => 'iyzico', 'installments' => 3, 'rate' => 1.50]);

        $ranked = app(GatewayRouter::class)->rankedFor(new CardProfile(family: 'Bonus', type: CardType::Credit), installments: 3);

        $this->assertArrayHasKey('iyzico', $ranked);
        $this->assertArrayNotHasKey('hoppa', $ranked);
    }
}