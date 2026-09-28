<?php

namespace XLaravel\Payline\Tests\Feature\Routing;

use XLaravel\Payline\DTOs\CommissionRateData;
use XLaravel\Payline\Enums\CardType;
use XLaravel\Payline\Models\CommissionRate;
use XLaravel\Payline\Tests\Fixtures\Gateways\RatedGateway;
use XLaravel\Payline\Tests\TestCase;

class SyncCommissionRatesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RatedGateway::reset();
        $this->app->make('payline')->extend('rated', fn () => new RatedGateway());
    }

    public function test_it_writes_the_rates_the_gateway_reports(): void
    {
        RatedGateway::willReturn([
            new CommissionRateData(rate: 2.03, installments: 1, cardFamily: 'Bonus'),
            new CommissionRateData(rate: 3.10, installments: 3, cardFamily: 'Bonus'),
        ]);

        $this->artisan('payline:sync-rates --gateway=rated')->assertSuccessful();

        $this->assertDatabaseHas('payline_commission_rates', [
            'gateway' => 'rated',
            'card_family' => 'Bonus',
            'installments' => 3,
            'rate' => 3.10,
        ]);
        $this->assertSame(2, CommissionRate::count());
    }

    public function test_a_second_run_updates_rather_than_duplicates(): void
    {
        RatedGateway::willReturn([new CommissionRateData(rate: 2.03, installments: 1, cardFamily: 'Bonus')]);
        $this->artisan('payline:sync-rates --gateway=rated');

        RatedGateway::willReturn([new CommissionRateData(rate: 1.50, installments: 1, cardFamily: 'Bonus')]);
        $this->artisan('payline:sync-rates --gateway=rated');

        $this->assertSame(1, CommissionRate::count());
        $this->assertSame('1.5000', CommissionRate::firstOrFail()->rate);
    }

    public function test_the_card_type_is_part_of_the_row_identity(): void
    {
        RatedGateway::willReturn([
            new CommissionRateData(rate: 2.00, cardFamily: 'Bonus', cardType: CardType::Credit),
            new CommissionRateData(rate: 1.20, cardFamily: 'Bonus', cardType: CardType::Debit),
        ]);

        $this->artisan('payline:sync-rates --gateway=rated');

        $this->assertSame(2, CommissionRate::count());
    }

    public function test_it_leaves_rates_entered_by_hand_alone(): void
    {
        CommissionRate::create(['gateway' => 'qnb', 'installments' => 1, 'rate' => 2.50]);
        RatedGateway::willReturn([new CommissionRateData(rate: 2.03, installments: 1)]);

        $this->artisan('payline:sync-rates --gateway=rated');

        $this->assertDatabaseHas('payline_commission_rates', ['gateway' => 'qnb', 'rate' => 2.50]);
    }

    public function test_a_rate_that_returns_restores_a_deleted_row(): void
    {
        RatedGateway::willReturn([new CommissionRateData(rate: 2.03, installments: 1, cardFamily: 'Bonus')]);
        $this->artisan('payline:sync-rates --gateway=rated');

        CommissionRate::firstOrFail()->delete();

        $this->artisan('payline:sync-rates --gateway=rated');

        $this->assertSame(1, CommissionRate::count());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        RatedGateway::willReturn([new CommissionRateData(rate: 2.03, installments: 1, cardFamily: 'Bonus')]);

        $this->artisan('payline:sync-rates --gateway=rated --dry-run')
            ->expectsOutputToContain('Bonus')
            ->assertSuccessful();

        $this->assertSame(0, CommissionRate::count());
    }

    public function test_a_gateway_without_a_rate_service_is_skipped(): void
    {
        $this->artisan('payline:sync-rates --gateway=fake')->assertSuccessful();

        $this->assertSame(0, CommissionRate::count());
    }

    public function test_an_unregistered_gateway_fails_the_run(): void
    {
        $this->artisan('payline:sync-rates --gateway=nowhere')->assertFailed();
    }

    public function test_a_refusing_gateway_fails_the_run_without_writing(): void
    {
        RatedGateway::willFail();

        $this->artisan('payline:sync-rates --gateway=rated')->assertFailed();

        $this->assertSame(0, CommissionRate::count());
    }

    public function test_without_a_gateway_option_every_registered_gateway_is_visited(): void
    {
        RatedGateway::willReturn([new CommissionRateData(rate: 2.03, installments: 1)]);

        $this->artisan('payline:sync-rates')->assertSuccessful();

        $this->assertDatabaseHas('payline_commission_rates', ['gateway' => 'rated']);
    }
}
