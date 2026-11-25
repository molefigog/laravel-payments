<?php

namespace Molefigog\Payments;

use Illuminate\Support\ServiceProvider;
use Molefigog\Payments\Console\InstallCommand;
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

            // Plain `vendor:publish` tags (the interactive way is: php artisan payments:install)
            $this->publishes([__DIR__.'/../resources/js/core' => resource_path('js/payments/core')], 'payments-js-core');
            $this->publishes([
                __DIR__.'/../resources/js/core' => resource_path('js/payments/core'),
                __DIR__.'/../resources/js/vue' => resource_path('js/payments/vue'),
            ], 'payments-vue');
            $this->publishes([
                __DIR__.'/../resources/views/components/payments' => resource_path('views/components/payments'),
                __DIR__.'/../resources/dist' => public_path('vendor/payments'),
            ], 'payments-blade');

            $this->commands([InstallCommand::class, SeedGatewaysCommand::class, ReconcileCommand::class]);
        }
    }
}
