<?php

namespace Molefigog\Payments\Traits;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Molefigog\Payments\Models\Payment;
use Molefigog\Payments\PaymentService;
use Molefigog\Payments\Support\ChargeRequest;
use Molefigog\Payments\Support\ChargeResult;
use Molefigog\Payments\Support\Models;

/** Add to your User model: `use HasPayments;` then `$user->charge(...)`. */
trait HasPayments
{
    public function payments(): HasMany
    {
        return $this->hasMany(Models::payment(), 'user_id');
    }

    /**
     * @param  int|string|array|null  $products  product id(s); null for a plain amount charge
     * @param  array  $options  amount, msisdn, description, currency, return_url, email, method, meta
     */
    public function charge(int|string|array|null $products, string $gateway, array $options = []): ChargeResult
    {
        return app(PaymentService::class)->charge(
            ChargeRequest::make($gateway, $products, $options, $this->getAuthIdentifier())
        );
    }

    public function hasPurchased(int|string $productId): bool
    {
        return $this->payments()->where('product_id', (string) $productId)->where('status', 'completed')->exists();
    }
}
