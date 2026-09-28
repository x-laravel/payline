<?php

namespace XLaravel\Payline;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\ServiceProvider;
use XLaravel\Payline\Console\PaylineDoctorCommand;
use XLaravel\Payline\Console\ReconcilePaymentsCommand;
use XLaravel\Payline\Console\SyncCommissionRatesCommand;
use XLaravel\Payline\Contracts\CallbackRedirectResolver;
use XLaravel\Payline\Gateway\GatewayInvoker;
use XLaravel\Payline\Gateway\GatewayResolver;
use XLaravel\Payline\Http\Controllers\CallbackController;
use XLaravel\Payline\Http\Controllers\WebhookController;
use XLaravel\Payline\Notifications\CallbackHandler;
use XLaravel\Payline\Payments\AmountLedger;
use XLaravel\Payline\Payments\TransactionRunner;
use XLaravel\Payline\Payments\TransactionUpdater;
use XLaravel\Payline\Routing\ConfigCallbackRedirectResolver;
use XLaravel\Payline\Routing\GatewayPolicyPipeline;
use XLaravel\Payline\Routing\GatewayRouter;
use XLaravel\Payline\StateMachine\PaymentStatusResolver;

class PaylineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/payline.php', 'payline');

        $this->registerManagers();
        $this->registerInternals();
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'payline');

        if ($this->app->runningInConsole()) {
            $this->registerPublishables();
            $this->commands([
                PaylineDoctorCommand::class,
                ReconcilePaymentsCommand::class,
                SyncCommissionRatesCommand::class,
            ]);
        }

        $this->registerRoutes();
    }

    private function registerManagers(): void
    {
        $this->app->singleton('payline', fn ($app) => new PaylineManager($app));
        $this->app->alias('payline', PaylineManager::class);

        $this->app->singleton('payline.bin_lookup', fn ($app) => new BinLookupManager($app));
        $this->app->alias('payline.bin_lookup', BinLookupManager::class);
    }

    private function registerInternals(): void
    {
        $this->app->singleton(TransactionRecorder::class);
        $this->app->singleton(TransactionRunner::class);
        $this->app->singleton(TransactionUpdater::class);
        $this->app->singleton(AmountLedger::class);
        $this->app->singleton(PaymentStatusResolver::class);
        $this->app->singleton(GatewayRouter::class);
        $this->app->singleton(GatewayPolicyPipeline::class);
        $this->app->singleton(GatewayResolver::class);
        $this->app->singleton(GatewayInvoker::class);
        $this->app->singleton(PaymentOperationValidator::class);
        $this->app->singleton(CallbackHandler::class);
        $this->app->singleton(IncomingNotificationProcessor::class);
        $this->app->bind(CallbackRedirectResolver::class, ConfigCallbackRedirectResolver::class);
    }

    private function registerPublishables(): void
    {
        $this->publishes([
            __DIR__ . '/../config/payline.php' => config_path('payline.php'),
        ], 'payline-config');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/payline'),
        ], 'payline-views');

        $this->publishesMigrations([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'payline-migrations');
    }

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
            ->withoutMiddleware(['csrf', PreventRequestForgery::class, VerifyCsrfToken::class])
            ->name('payline.callback');

        $router->post("{$prefix}/webhooks/{gateway}", WebhookController::class)
            ->middleware(array_merge($middleware, $webhookMiddleware))
            ->withoutMiddleware(['web', 'csrf', PreventRequestForgery::class, VerifyCsrfToken::class])
            ->name('payline.webhook');
    }
}
