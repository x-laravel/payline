<?php

namespace XLaravel\Payline;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\ServiceProvider;
use XLaravel\Payline\Console\PaylineDoctorCommand;
use XLaravel\Payline\Console\ReconcilePaymentsCommand;
use XLaravel\Payline\Contracts\CallbackRedirectResolver;
use XLaravel\Payline\Http\Controllers\CallbackController;
use XLaravel\Payline\Http\Controllers\WebhookController;
use XLaravel\Payline\Routing\GatewayRouter;
use XLaravel\Payline\Routing\GatewayPolicyPipeline;
use XLaravel\Payline\Routing\ConfigCallbackRedirectResolver;

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
            $this->commands([
                PaylineDoctorCommand::class,
                ReconcilePaymentsCommand::class,
            ]);
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
        $this->app->singleton(GatewayPolicyPipeline::class);
        $this->app->singleton(PaymentOperationValidator::class);
        $this->app->singleton(IncomingNotificationProcessor::class);
        $this->app->bind(CallbackRedirectResolver::class, ConfigCallbackRedirectResolver::class);
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
        $webhookMiddleware = $this->app['config']->get('payline.routes.webhook_middleware', []);

        $router = $this->app['router'];

        $router->match(['GET', 'POST'], "{$prefix}/callback/{gateway}", CallbackController::class)
            ->middleware($middleware)
            ->withoutMiddleware(['csrf', VerifyCsrfToken::class])
            ->name('payline.callback');

        $router->post("{$prefix}/webhooks/{gateway}", WebhookController::class)
            ->middleware(array_merge($middleware, $webhookMiddleware))
            ->withoutMiddleware(['web', 'csrf', VerifyCsrfToken::class])
            ->name('payline.webhook');
    }
}
