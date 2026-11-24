<?php

namespace Molefigog\Payments\Events;

use Illuminate\Support\Collection;

class PaymentFailed
{
    public function __construct(
        public readonly Collection $payments,
        public readonly ?string $reason = null,
    ) {
    }

    public function reference(): string
    {
        return (string) $this->payments->first()->reference;
    }

    public function gateway(): string
    {
        return (string) $this->payments->first()->gateway;
    }
}
