<?php

namespace XLaravel\Payline;

use Illuminate\Support\ServiceProvider;
use XLaravel\Payline\Routing\GatewayRouter;

class PaylineServiceProvider extends ServiceProvider
{
    /** Merges config and registers singletons — runs before boot(). */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/payline.php', 'payline');

        $this->registerManagers();
        $this->registerInternals();
    }

    /** Registers publishables, loads migrations, and registers routes. */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->registerPublishables();
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->registerRoutes();
    }

    /** Registers PaylineManager and BinLookupManager as singletons. */
    private function registerManagers(): void
    {
        $this->app->singleton('payline', fn ($app) => new PaylineManager($app));
        $this->app->alias('payline', PaylineManager::class);

        $this->app->singleton('payline.bin_lookup', fn ($app) => new BinLookupManager($app));
        $this->app->alias('payline.bin_lookup', BinLookupManager::class);
    }

    /** Registers internal services for transaction recording and gateway routing. */
    private function registerInternals(): void
    {
        $this->app->singleton(TransactionRecorder::class);
        $this->app->singleton(GatewayRouter::class);
    }

    /** Publishes config and migration files. */
    private function registerPublishables(): void
    {
        $this->publishes([
            __DIR__ . '/../config/payline.php' => config_path('payline.php'),
        ], 'payline-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'payline-migrations');
    }

    /** Registers callback and webhook routes based on config. */
    private function registerRoutes(): void
    {
        if (! $this->app['config']->get('payline.routes.enabled', true)) {
            return;
        }

        $prefix = $this->app['config']->get('payline.routes.prefix', 'payline');
        $middleware = $this->app['config']->get('payline.routes.middleware', ['web']);

        $this->app['router']
            ->prefix($prefix)
            ->middleware($middleware)
            ->group(__DIR__ . '/../routes/payline.php');
    }
}
