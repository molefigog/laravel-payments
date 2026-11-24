<?php

namespace Molefigog\Payments\Events;

use Illuminate\Support\Collection;

/** Fired once per charge. $payments holds one Payment row per product. */
class PaymentInitiated
{
    public function __construct(public readonly Collection $payments)
    {
    }

    public function reference(): string
    {
        return (string) $this->payments->first()->reference;
    }

    public function gateway(): string
    {
        return (string) $this->payments->first()->gateway;
    }

    public function total(): float
    {
        return round((float) $this->payments->sum('amount'), 2);
    }

    /** @return array<int, string> */
    public function productIds(): array
    {
        return $this->payments->pluck('product_id')->filter()->map(fn ($id) => (string) $id)->values()->all();
    }
}
