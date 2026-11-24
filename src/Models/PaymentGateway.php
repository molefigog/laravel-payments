<?php

namespace Molefigog\Payments\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentGateway extends Model
{
    protected $guarded = [];

    protected $hidden = ['credentials'];

    protected $casts = [
        'enabled' => 'boolean',
        'credentials' => 'encrypted:array',
        'settings' => 'array',
        'sort_order' => 'integer',
    ];

    public function getTable(): string
    {
        return config('payments.tables.gateways', 'payment_gateways');
    }
}
