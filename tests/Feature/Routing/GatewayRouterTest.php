<?php

namespace XLaravel\Payline\Tests\Feature\Routing;

use XLaravel\Payline\DTOs\CardProfile;
use XLaravel\Payline\Enums\CardType;
use XLaravel\Payline\Facades\Payline;
use XLaravel\Payline\Models\CommissionRate;
use XLaravel\Payline\Routing\GatewayRouter;
use XLaravel\Payline\Tests\TestCase;

class GatewayRouterTest extends TestCase
{
    protected function tearDown(): void
    {
        Payline::rankUsing(null);

        parent::tearDown();
    }

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

    public function test_the_commission_rate_is_the_cost_until_something_says_otherwise(): void
    {
        CommissionRate::create(['gateway' => 'hoppa', 'installments' => 1, 'rate' => 2.25, 'blocking_days' => 15]);
        CommissionRate::create(['gateway' => 'qnb', 'installments' => 1, 'rate' => 2.91, 'blocking_days' => 5]);

        $ranked = app(GatewayRouter::class)->rankedFor(new CardProfile(family: 'bonus', type: CardType::Credit));

        $this->assertSame(['hoppa', 'qnb'], array_keys($ranked));
        $this->assertSame(2.25, $ranked['hoppa']);
    }

    public function test_the_application_decides_what_a_gateway_costs(): void
    {
        Payline::rankUsing(fn (CommissionRate $rate) => (float) $rate->rate + 45 * $rate->blocking_days / 365);

        CommissionRate::create(['gateway' => 'hoppa', 'installments' => 1, 'rate' => 2.25, 'blocking_days' => 15]);
        CommissionRate::create(['gateway' => 'qnb', 'installments' => 1, 'rate' => 2.91, 'blocking_days' => 5]);

        $ranked = app(GatewayRouter::class)->rankedFor(new CardProfile(family: 'bonus', type: CardType::Credit));

        $this->assertSame(['qnb', 'hoppa'], array_keys($ranked));
        $this->assertEqualsWithDelta(3.5264, $ranked['qnb'], 0.0001);
        $this->assertEqualsWithDelta(4.0993, $ranked['hoppa'], 0.0001);
    }

    public function test_the_card_reaches_the_ranking_so_it_can_be_priced_on_where_it_came_from(): void
    {
        Payline::rankUsing(fn (CommissionRate $rate, CardProfile $profile) => $rate->gateway === 'qnb' && $profile->issuedOutside('TR')
            ? 0.0
            : (float) $rate->rate);

        CommissionRate::create(['gateway' => 'hoppa', 'installments' => 1, 'rate' => 2.25]);
        CommissionRate::create(['gateway' => 'qnb', 'installments' => 1, 'rate' => 2.91]);

        $domestic = app(GatewayRouter::class)->rankedFor(new CardProfile(issuerCountry: 'TR'));
        $foreign = app(GatewayRouter::class)->rankedFor(new CardProfile(issuerCountry: 'DE'));

        $this->assertSame(['hoppa', 'qnb'], array_keys($domestic));
        $this->assertSame(['qnb', 'hoppa'], array_keys($foreign));
        $this->assertSame(0.0, $foreign['qnb']);
    }

    public function test_the_installment_count_reaches_the_ranking(): void
    {
        Payline::rankUsing(fn (CommissionRate $rate, CardProfile $profile, int $installments) => (float) $installments);

        CommissionRate::create(['gateway' => 'hoppa', 'installments' => 3, 'rate' => 2.25]);

        $ranked = app(GatewayRouter::class)->rankedFor(new CardProfile(), installments: 3);

        $this->assertSame(3.0, $ranked['hoppa']);
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