<?php

use Illuminate\Support\Facades\Route;
use Molefigog\Payments\Http\Controllers\CallbackController;
use Molefigog\Payments\Http\Controllers\PaymentController;

$cfg = config('payments.routes');
$throttle = array_filter([$cfg['throttle'] ?? null]);

Route::prefix($cfg['prefix'])
    ->middleware($cfg['middleware'] ?? ['api'])
    ->name('payments.')
    ->group(function () use ($cfg, $throttle) {

        // Public: gateways the frontend may offer, plus non-secret config (e.g. PayPal client id).
        Route::get('gateways', [PaymentController::class, 'gateways'])->name('gateways');

        // Providers / browsers calling back. Unauthenticated by nature.
        Route::match(['get', 'post'], 'callback/{gateway}', CallbackController::class)->name('callback');

        // Customer actions.
        Route::middleware(array_merge((array) ($cfg['auth_middleware'] ?? []), $throttle))->group(function () {
            Route::post('charge', [PaymentController::class, 'charge'])->name('charge');
            Route::post('confirm', [PaymentController::class, 'confirm'])->name('confirm');
            Route::get('status/{reference}', [PaymentController::class, 'status'])->name('status');
        });
    });
