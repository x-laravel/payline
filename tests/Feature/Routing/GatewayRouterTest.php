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

        $cheapest = app(GatewayRouter::class)->cheapestFor(new CardProfile('Bonus', CardType::Credit));

        $this->assertSame('hoppa', $cheapest);
    }

    public function test_returns_null_when_no_rates_exist(): void
    {
        $cheapest = app(GatewayRouter::class)->cheapestFor(new CardProfile('Bonus', CardType::Credit));

        $this->assertNull($cheapest);
    }

    public function test_rankedFor_returns_gateways_sorted_ascending_by_rate(): void
    {
        CommissionRate::create(['gateway' => 'iyzico', 'installments' => 1, 'rate' => 2.92]);
        CommissionRate::create(['gateway' => 'hoppa', 'installments' => 1, 'rate' => 2.03]);
        CommissionRate::create(['gateway' => 'qnb', 'installments' => 1, 'rate' => 2.50]);

        $ranked = app(GatewayRouter::class)->rankedFor(new CardProfile('Bonus', CardType::Credit));

        $this->assertSame(['hoppa', 'qnb', 'iyzico'], array_keys($ranked));
    }

    public function test_specific_card_family_rate_takes_priority_over_wildcard_for_same_gateway(): void
    {
        // qnb has a wildcard rate (1.00) and a Bonus-specific rate (3.00)
        // For a Bonus card, the specific rate should be used, not the wildcard
        CommissionRate::create(['gateway' => 'qnb', 'card_family' => null, 'installments' => 1, 'rate' => 1.00]);
        CommissionRate::create(['gateway' => 'qnb', 'card_family' => 'Bonus', 'installments' => 1, 'rate' => 3.00]);

        $ranked = app(GatewayRouter::class)->rankedFor(new CardProfile('Bonus', CardType::Credit));

        $this->assertSame(3.00, $ranked['qnb']);
    }

    public function test_wildcard_rate_applies_when_no_specific_match(): void
    {
        CommissionRate::create(['gateway' => 'hoppa', 'card_family' => null, 'installments' => 1, 'rate' => 2.50]);

        $ranked = app(GatewayRouter::class)->rankedFor(new CardProfile('Axess', CardType::Credit));

        $this->assertArrayHasKey('hoppa', $ranked);
        $this->assertSame(2.50, $ranked['hoppa']);
    }

    public function test_soft_deleted_rates_are_excluded(): void
    {
        CommissionRate::create(['gateway' => 'hoppa', 'installments' => 1, 'rate' => 2.03]);
        CommissionRate::create(['gateway' => 'iyzico', 'installments' => 1, 'rate' => 2.92])->delete();

        $ranked = app(GatewayRouter::class)->rankedFor(new CardProfile('Bonus', CardType::Credit));

        $this->assertArrayHasKey('hoppa', $ranked);
        $this->assertArrayNotHasKey('iyzico', $ranked);
    }

    public function test_installment_count_is_respected(): void
    {
        CommissionRate::create(['gateway' => 'hoppa', 'installments' => 1, 'rate' => 2.03]);
        CommissionRate::create(['gateway' => 'iyzico', 'installments' => 3, 'rate' => 1.50]);

        $ranked = app(GatewayRouter::class)->rankedFor(new CardProfile('Bonus', CardType::Credit), installments: 3);

        $this->assertArrayHasKey('iyzico', $ranked);
        $this->assertArrayNotHasKey('hoppa', $ranked);
    }
}