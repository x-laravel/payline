<?php

namespace XLaravel\Payline\Tests\Feature\Routing;

use XLaravel\Payline\DTOs\CardProfile;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\Enums\CardType;
use XLaravel\Payline\Enums\PaymentMethod;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Gateway\GatewayResolver;
use XLaravel\Payline\Models\CommissionRate;
use XLaravel\Payline\Tests\Fixtures\Gateways\LimitedGateway;
use XLaravel\Payline\Tests\TestCase;

class CapabilityFilteringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('payline')->extend('limited', fn () => new LimitedGateway());
    }

    public function test_a_gateway_takes_a_request_inside_its_declared_capabilities(): void
    {
        $this->assertTrue($this->supports($this->request()));
    }

    public function test_an_undeclared_payment_method_is_refused(): void
    {
        $this->assertFalse($this->supports($this->request(method: PaymentMethod::DebitCard)));
    }

    public function test_a_request_without_a_method_is_not_refused_on_that_ground(): void
    {
        $this->assertTrue($this->supports($this->request(method: null)));
    }

    public function test_an_undeclared_currency_is_refused(): void
    {
        $this->assertFalse($this->supports($this->request(currency: 'USD')));
    }

    public function test_an_undeclared_installment_count_is_refused(): void
    {
        $this->assertFalse($this->supports($this->request(installments: 6)));
        $this->assertTrue($this->supports($this->request(installments: 3)));
    }

    public function test_a_request_without_three_d_secure_is_refused(): void
    {
        $this->assertFalse($this->supports($this->request(threeDs: false)));
    }

    public function test_an_undeclared_operation_is_refused(): void
    {
        $this->assertFalse($this->supports($this->request(), TransactionType::Refund));
    }

    public function test_a_driver_without_declared_capabilities_restricts_nothing(): void
    {
        $resolver = app(GatewayResolver::class);
        $fake = $this->app->make('payline')->driver('fake');

        $this->assertTrue($resolver->supports($fake, $this->request(currency: 'USD'), TransactionType::Payment));
    }

    public function test_routing_passes_over_a_cheaper_gateway_that_cannot_take_the_request(): void
    {
        CommissionRate::create(['gateway' => 'limited', 'installments' => 1, 'rate' => 1.00]);
        CommissionRate::create(['gateway' => 'fake', 'installments' => 1, 'rate' => 2.00]);

        $gateway = app(GatewayResolver::class)->forRequest(
            $this->request(currency: 'USD', profile: new CardProfile('Bonus', CardType::Credit)),
            TransactionType::Payment,
            null,
            true,
        );

        $this->assertSame('fake', $gateway->getName());
    }

    public function test_routing_takes_the_cheapest_gateway_that_can(): void
    {
        CommissionRate::create(['gateway' => 'limited', 'installments' => 1, 'rate' => 1.00]);
        CommissionRate::create(['gateway' => 'fake', 'installments' => 1, 'rate' => 2.00]);

        $gateway = app(GatewayResolver::class)->forRequest(
            $this->request(profile: new CardProfile('Bonus', CardType::Credit)),
            TransactionType::Payment,
            null,
            true,
        );

        $this->assertSame('limited', $gateway->getName());
    }

    public function test_routing_skips_a_rate_row_naming_an_unregistered_gateway(): void
    {
        CommissionRate::create(['gateway' => 'nowhere', 'installments' => 1, 'rate' => 0.10]);
        CommissionRate::create(['gateway' => 'limited', 'installments' => 1, 'rate' => 1.00]);

        $gateway = app(GatewayResolver::class)->forRequest(
            $this->request(profile: new CardProfile('Bonus', CardType::Credit)),
            TransactionType::Payment,
            null,
            true,
        );

        $this->assertSame('limited', $gateway->getName());
    }

    private function supports(PaymentRequest $data, TransactionType $type = TransactionType::Payment): bool
    {
        return app(GatewayResolver::class)->supports(
            $this->app->make('payline')->driver('limited'),
            $data,
            $type,
        );
    }

    private function request(
        string $currency = 'TRY',
        ?PaymentMethod $method = PaymentMethod::CreditCard,
        ?int $installments = 1,
        bool $threeDs = true,
        ?CardProfile $profile = null,
    ): PaymentRequest {
        return new PaymentRequest(
            reference: 'ORD-001',
            amount: 10000,
            currency: $currency,
            method: $method,
            threeDs: $threeDs,
            installments: $installments,
            cardProfile: $profile,
        );
    }
}
