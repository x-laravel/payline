<?php

namespace XLaravel\Payline\Tests\Feature\Routing;

use RuntimeException;
use XLaravel\Payline\PaylineManager;
use XLaravel\Payline\Tests\Fixtures\Gateways\FakeGateway;
use XLaravel\Payline\Tests\Fixtures\Gateways\LimitedGateway;
use XLaravel\Payline\Tests\TestCase;

class GatewayManagerTest extends TestCase
{
    private function manager(): PaylineManager
    {
        return $this->app->make('payline');
    }

    public function test_the_default_gateway_is_resolved_without_a_name(): void
    {
        $this->assertInstanceOf(FakeGateway::class, $this->manager()->gateway());
    }

    public function test_a_named_gateway_is_resolved(): void
    {
        $this->manager()->extend('limited', fn () => new LimitedGateway());

        $this->assertInstanceOf(LimitedGateway::class, $this->manager()->gateway('limited'));
    }

    public function test_driver_resolves_the_same_instance_as_gateway(): void
    {
        $this->assertSame($this->manager()->gateway(), $this->manager()->driver());
        $this->assertSame($this->manager()->gateway('fake'), $this->manager()->driver('fake'));
    }

    public function test_a_registered_gateway_is_reported(): void
    {
        $this->assertTrue($this->manager()->hasGateway('fake'));
        $this->assertFalse($this->manager()->hasGateway('absent'));
    }

    public function test_registered_gateways_are_listed_by_name(): void
    {
        $this->manager()->extend('limited', fn () => new LimitedGateway());

        $this->assertSame(['fake', 'limited'], $this->manager()->registeredGateways());
    }

    public function test_resolving_without_a_configured_default_throws(): void
    {
        config(['payline.default' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PAYLINE_GATEWAY');

        $this->manager()->gateway();
    }
}
