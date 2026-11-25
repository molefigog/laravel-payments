{{--
    Blade checkout button + dialog. Yours to restyle once published.

    <x-payments.checkout :product-ids="[$song->id]" label="Buy now" :gateways="['mpesa','ecocash']" />

    Needs (the installer adds both):  public/vendor/payments/payments-core.global.js  and  payments-blade.js
    Auth: the package routes use `auth:sanctum` by default. For a classic session-auth Blade app set in config/payments.php:
          'routes' => ['middleware' => ['web'], 'auth_middleware' => ['auth']]   (this component sends the CSRF header for you)
--}}
@props([
    'productIds' => [],
    'label' => 'Buy now',
    'title' => 'Pay',
    'gateways' => [],
    'returnUrl' => null,
    'amount' => null,
    'description' => null,
    'baseUrl' => null,
])

@php
    $uid = 'payments-'.\Illuminate\Support\Str::random(8);
    $options = [
        'baseUrl' => $baseUrl ?: url(config('payments.routes.prefix', 'api/payments')),
        'productIds' => array_values((array) $productIds),
        'gateways' => array_values((array) $gateways),
        'title' => $title,
        'returnUrl' => $returnUrl,
        'amount' => $amount,
        'description' => $description,
    ];
@endphp

<button type="button" id="{{ $uid }}-open" {{ $attributes->merge(['class' => 'payments-open']) }}>{{ $label }}</button>

<dialog id="{{ $uid }}" class="payments-dialog">
    <div class="payments-head">
        <strong data-payments-title></strong>
        <button type="button" class="payments-x" data-payments-close aria-label="Close">&times;</button>
    </div>
    <div data-payments-body></div>
</dialog>

@once
    <style>
        .payments-dialog { border: 0; border-radius: 12px; padding: 20px; width: 400px; max-width: 94vw; box-shadow: 0 10px 40px rgba(0,0,0,.25); }
        .payments-dialog::backdrop { background: rgba(0,0,0,.45); }
        .payments-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .payments-x { border: 0; background: none; font-size: 22px; cursor: pointer; }
        .payments-gateway { display: flex; gap: 10px; align-items: center; padding: 10px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 8px; cursor: pointer; }
        .payments-input { width: 100%; box-sizing: border-box; padding: 10px; border: 1px solid #ccc; border-radius: 8px; margin: 8px 0; }
        .payments-btn { display: inline-block; padding: 10px 16px; border: 1px solid #ccc; background: #fff; border-radius: 8px; cursor: pointer; margin: 6px 6px 0 0; text-decoration: none; color: inherit; }
        .payments-btn-primary { width: 100%; margin-right: 0; background: #1976d2; border-color: #1976d2; color: #fff; }
        .payments-btn:disabled { opacity: .5; cursor: not-allowed; }
        .payments-center { text-align: center; }
        .payments-small { font-size: 12px; color: #777; text-align: center; }
        .payments-ok { color: #2e7d32; font-size: 20px; }
        .payments-bad { color: #c62828; }
        .payments-frame { width: 100%; height: 420px; border: 0; }
    </style>
    <script src="{{ asset('vendor/payments/payments-core.global.js') }}"></script>
    <script src="{{ asset('vendor/payments/payments-blade.js') }}"></script>
@endonce

<script>
    window.PaymentsBlade.mount(@json($uid), @json($options));
</script>
