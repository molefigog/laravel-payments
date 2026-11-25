<?php

namespace Molefigog\Payments\Support;

use Molefigog\Payments\Models\Payment;
use Molefigog\Payments\Models\PaymentGateway;

/**
 * Resolves the Eloquent classes the package uses, so an app can extend them
 * (App\Models\Payment extends the package model) and point `payments.models` at its own.
 */
final class Models
{
    /** @return class-string<Payment> */
    public static function payment(): string
    {
        return config('payments.models.payment') ?: Payment::class;
    }

    /** @return class-string<PaymentGateway> */
    public static function gateway(): string
    {
        return config('payments.models.gateway') ?: PaymentGateway::class;
    }
}
