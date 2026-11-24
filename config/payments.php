<?php

use Molefigog\Payments\Gateways;

return [

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    */
    'currency' => env('PAYMENTS_CURRENCY', 'LSL'),

    // Class implementing Molefigog\Payments\Contracts\ProductResolver.
    // When set, prices/titles/payees are looked up on the SERVER from product ids
    // and any amount sent by the client is ignored. Strongly recommended.
    'resolver' => null,

    // Where the browser is sent after a redirect-style payment (PayPal, CPay card).
    // A per-charge `return_url` option overrides it (must pass the allow-lists below).
    'result_url' => env('PAYMENTS_RESULT_URL'),
    'allowed_redirect_hosts' => [],      // e.g. ['app.example.com']; the app.url host is always allowed
    'allowed_redirect_schemes' => [],    // e.g. ['com.example.app'] for mobile deep links

    'tables' => [
        'payments' => 'payments',
        'gateways' => 'payment_gateways',
    ],

    'routes' => [
        'enabled' => true,
        'prefix' => 'api/payments',
        'middleware' => ['api'],                 // applied to all routes
        'auth_middleware' => ['auth:sanctum'],   // charge / confirm / status. Use [] to allow guests.
        'throttle' => 'throttle:60,1',
    ],

    'polling' => [
        'max_attempts' => 40,
        // With QUEUE_CONNECTION=sync, delayed polling cannot work; clients then poll GET /status/{reference}.
        'allow_sync_queue' => false,
        'connection' => null,
        'queue' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Drivers (driver name => class). Add your own with GatewayManager::extend().
    |--------------------------------------------------------------------------
    */
    'drivers' => [
        'mpesa' => Gateways\MpesaGateway::class,
        'ecocash' => Gateways\EcocashGateway::class,
        'cpay' => Gateways\CpayGateway::class,
        'paypal' => Gateways\PaypalGateway::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Gateways
    |--------------------------------------------------------------------------
    | Defaults read from .env. A row in the payment_gateways table with the same
    | slug overrides these (credentials/settings are merged on top), which is how
    | a UI can manage them. A slug can use any driver, so you can run several
    | gateways of one driver (e.g. cpay-card + cpay-mobile).
    */
    'gateways' => [

        'mpesa' => [
            'driver' => 'mpesa',
            'name' => 'M-Pesa',
            'enabled' => env('PAYMENTS_MPESA_ENABLED', true),
            'mode' => env('PESA_ENV', 'sandbox'),               // sandbox | live
            'currency' => 'LSL',
            'credentials' => [
                'api_key' => env('PESA_API_KEY'),
                'public_key' => env('PESA_PUBLIC_KEY'),
                'short_code' => env('PESA_SC'),
            ],
            'settings' => [
                'market' => 'vodacomLES',
                'country' => 'LES',
                'msisdn_prefix' => '266',
                'transaction_reference' => env('PESA_TXN_REFERENCE', env('APP_NAME', 'payment')),
                'poll_interval' => 15,
                'grace_seconds' => 240,
            ],
        ],

        'ecocash' => [
            'driver' => 'ecocash',
            'name' => 'EcoCash',
            'enabled' => env('PAYMENTS_ECOCASH_ENABLED', true),
            'mode' => 'live',
            'currency' => 'LSL',
            'credentials' => [
                'merchant_id' => env('ECOCASH_MERCHANT_ID'),
                'merchant_name' => env('ECOCASH_MERCHANT_NAME'),
                'token' => env('ECOCASH_TOKEN'),
            ],
            'settings' => [
                'base_url' => 'https://api.paylesotho.co.ls',
                'poll_interval' => 5,
                'timeout_seconds' => 180,
            ],
        ],

        'cpay' => [
            'driver' => 'cpay',
            'name' => 'CPay Mobile',
            'enabled' => env('PAYMENTS_CPAY_ENABLED', true),
            'mode' => env('CPAY_MODE', 'sandbox'),
            'currency' => 'LSL',
            'credentials' => [
                'client_code' => env('CPAY_CODE'),
                'api_key' => env('CPAY_API'),
                'secret' => env('CPAY_SECRET'),
            ],
            'settings' => [
                'base_url' => env('CPAY_URL', 'https://cpay-uat-env.chaperone.co.ls:5100'),
                'method' => 'mobile',        // mobile (OTP) | card (redirect/iframe)
                'verify_ssl' => env('CPAY_VERIFY_SSL', true),
                'otp_medium' => 'sms',
            ],
        ],

        'paypal' => [
            'driver' => 'paypal',
            'name' => 'PayPal',
            'enabled' => env('PAYMENTS_PAYPAL_ENABLED', true),
            'mode' => env('PAYPAL_MODE', 'sandbox'),            // sandbox | live
            'currency' => 'USD',
            'credentials' => [
                'client_id' => env('PAYPAL_CLIENT_ID'),
                'secret' => env('PAYPAL_SECRET'),
            ],
            'settings' => [
                'paypal_currency' => 'USD',
                // Charges in another currency are converted. LSL is pegged 1:1 to ZAR,
                // so set fx_source_currency to ZAR if your rate source lacks LSL.
                'fx_source_currency' => env('PAYPAL_FX_SOURCE'),
                'fx_markup' => env('PAYPAL_FX_MARKUP', 0.04),
                'fx_fallback_rate' => env('PAYPAL_FX_FALLBACK_RATE'),
                'fx_api_key' => env('CURRENCY_API_KEY'),
            ],
        ],
    ],
];
