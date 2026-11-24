<?php

namespace Molefigog\Payments;

use Illuminate\Support\ServiceProvider;
use Molefigog\Payments\Console\ReconcileCommand;
use Molefigog\Payments\Console\SeedGatewaysCommand;

class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/payments.php', 'payments');

        $this->app->singleton(GatewayManager::class, fn ($app) => new GatewayManager($app));
        $this->app->singleton(PaymentService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (config('payments.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/payments.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/payments.php' => config_path('payments.php')], 'payments-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'payments-migrations');

            $this->commands([SeedGatewaysCommand::class, ReconcileCommand::class]);
        }
    }
}
