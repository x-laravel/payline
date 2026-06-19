<?php

namespace XLaravel\Payline\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use XLaravel\Payline\PaylineServiceProvider;
use XLaravel\Payline\Tests\Fixtures\Gateways\FakeGateway;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');

        $this->app->make('payline')->extend('fake', fn ($app, $config) => new FakeGateway());
    }

    protected function getPackageProviders($app): array
    {
        return [
            PaylineServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('payline.default', 'fake');
        $app['config']->set('payline.database.connection', 'sqlite');
    }
}
