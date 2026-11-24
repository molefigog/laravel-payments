<?php

namespace Molefigog\Payments\Facades;

use Illuminate\Support\Facades\Facade;
use Molefigog\Payments\PaymentService;

/**
 * @method static \Molefigog\Payments\Support\ChargeResult charge(\Molefigog\Payments\Support\ChargeRequest $request)
 * @method static \Molefigog\Payments\Support\ChargeResult confirm(string $reference, array $input)
 * @method static \Molefigog\Payments\Support\ChargeResult|null refresh(string $reference)
 * @method static \Illuminate\Support\Collection rows(string $idOrReference)
 */
class Payments extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PaymentService::class;
    }
}
