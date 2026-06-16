<?php

namespace XLaravel\Payline;

use Illuminate\Support\ServiceProvider;
use XLaravel\Payline\Routing\GatewayRouter;

class PaylineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/payline.php', 'payline');

        $this->app->singleton('payline', fn ($app) => new PaylineManager($app));
        $this->app->alias('payline', PaylineManager::class);
        $this->app->singleton(TransactionRecorder::class);
        $this->app->singleton(GatewayRouter::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/payline.php' => config_path('payline.php'),
            ], 'payline-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'payline-migrations');
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->registerRoutes();
    }

    protected function registerRoutes(): void
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
