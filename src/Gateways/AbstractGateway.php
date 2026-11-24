<?php

namespace Molefigog\Payments\Gateways;

use Illuminate\Support\Facades\Route;
use Molefigog\Payments\Contracts\PaymentGateway;
use Molefigog\Payments\Support\Charge;
use Molefigog\Payments\Support\GatewayResponse;

abstract class AbstractGateway implements PaymentGateway
{
    /** @param array{slug:string,name:string,mode:?string,currency:?string,credentials:array,settings:array} $config */
    public function __construct(protected array $config)
    {
    }

    abstract public function initiate(Charge $charge): GatewayResponse;

    public function confirm(Charge $charge, array $input): GatewayResponse
    {
        return GatewayResponse::failed('This gateway has no confirmation step.');
    }

    public function status(Charge $charge): GatewayResponse
    {
        return GatewayResponse::pending();
    }

    public function callbackReference(array $payload): ?string
    {
        $ref = $payload['reference'] ?? null;

        return is_string($ref) && $ref !== '' ? $ref : null;
    }

    public function callback(Charge $charge, array $payload): GatewayResponse
    {
        return $this->status($charge);
    }

    public function pollInterval(): ?int
    {
        $v = $this->setting('poll_interval');

        return $v === null ? null : (int) $v;
    }

    public function timeoutSeconds(): ?int
    {
        $v = $this->setting('timeout_seconds');

        return $v === null ? null : (int) $v;
    }

    public function publicConfig(): array
    {
        return [];
    }

    protected function cred(string $key, mixed $default = null): mixed
    {
        return $this->config['credentials'][$key] ?? $default;
    }

    protected function setting(string $key, mixed $default = null): mixed
    {
        return $this->config['settings'][$key] ?? $default;
    }

    protected function isLive(): bool
    {
        return in_array(strtolower((string) ($this->config['mode'] ?? '')), ['live', 'production', 'prod'], true);
    }

    protected function slug(): string
    {
        return (string) ($this->config['slug'] ?? '');
    }

    protected function money(float $amount): string
    {
        return number_format(round($amount, 2), 2, '.', '');
    }

    /** Absolute URL of this package's callback route for the charge (falls back to the site root). */
    protected function callbackUrl(Charge $charge, array $query = []): string
    {
        $query = array_merge(['reference' => $charge->reference], $query);

        if (Route::has('payments.callback')) {
            return route('payments.callback', ['gateway' => $charge->gateway] + $query);
        }

        return url('/').'?'.http_build_query($query);
    }
}
