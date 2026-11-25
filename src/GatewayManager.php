<?php

namespace Molefigog\Payments;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Molefigog\Payments\Contracts\PaymentGateway;
use Molefigog\Payments\Exceptions\PaymentException;
use Molefigog\Payments\Models\PaymentGateway as GatewayModel;
use Throwable;
use Molefigog\Payments\Support\Models;

class GatewayManager
{
    /** @var array<string, Closure|string> */
    protected array $custom = [];

    public function __construct(protected Container $app)
    {
    }

    /** Register a driver at runtime: GatewayManager::extend('flutterwave', FlutterwaveGateway::class) */
    public function extend(string $driver, Closure|string $factory): static
    {
        $this->custom[$driver] = $factory;

        return $this;
    }

    /**
     * Definition of one gateway: config defaults with the DB row (if any) merged on top.
     *
     * @return array{slug:string,name:string,driver:string,enabled:bool,mode:?string,currency:?string,credentials:array,settings:array,sort_order:int}|null
     */
    public function definition(string $slug): ?array
    {
        $config = config("payments.gateways.$slug");
        $row = $this->row($slug);

        if ($config === null && $row === null) {
            return null;
        }

        $config = (array) $config;

        return [
            'slug' => $slug,
            'name' => $row?->name ?: ($config['name'] ?? $slug),
            'driver' => $row?->driver ?: ($config['driver'] ?? $slug),
            'enabled' => $row ? (bool) $row->enabled : (bool) ($config['enabled'] ?? true),
            'mode' => $row?->mode ?: ($config['mode'] ?? null),
            'currency' => $row?->currency ?: ($config['currency'] ?? null),
            'credentials' => array_replace((array) ($config['credentials'] ?? []), array_filter((array) ($row?->credentials ?? []), fn ($v) => $v !== null && $v !== '')),
            'settings' => array_replace((array) ($config['settings'] ?? []), (array) ($row?->settings ?? [])),
            'sort_order' => (int) ($row?->sort_order ?? 0),
        ];
    }

    /** All enabled gateways (config + DB), ordered. @return Collection<int, array> */
    public function enabled(): Collection
    {
        $slugs = collect(array_keys((array) config('payments.gateways', [])));

        try {
            $slugs = $slugs->merge(Models::gateway()::query()->pluck('slug'));
        } catch (Throwable) {
            // table not migrated yet: config only
        }

        return $slugs->unique()
            ->map(fn ($slug) => $this->definition($slug))
            ->filter(fn ($d) => $d && $d['enabled'])
            ->sortBy('sort_order')
            ->values();
    }

    /**
     * Build the gateway for a slug. Disabled gateways are refused for NEW charges,
     * but $allowDisabled lets in-flight payments still be confirmed/polled.
     */
    public function driver(string $slug, bool $allowDisabled = false): PaymentGateway
    {
        $definition = $this->definition($slug);

        if ($definition === null) {
            throw new PaymentException("Unknown payment gateway [$slug].");
        }

        if (! $definition['enabled'] && ! $allowDisabled) {
            throw new PaymentException("Payment gateway [$slug] is disabled.");
        }

        $driver = $definition['driver'];
        $factory = $this->custom[$driver] ?? config("payments.drivers.$driver");

        if ($factory === null) {
            throw new PaymentException("No driver [$driver] registered for gateway [$slug].");
        }

        $instance = $factory instanceof Closure
            ? $factory($definition, $this->app)
            : $this->app->make($factory, ['config' => $definition]);

        if (! $instance instanceof PaymentGateway) {
            throw new PaymentException("Driver [$driver] must implement ".PaymentGateway::class.'.');
        }

        return $instance;
    }

    protected function row(string $slug): ?GatewayModel
    {
        try {
            return Models::gateway()::query()->where('slug', $slug)->first();
        } catch (Throwable) {
            return null;
        }
    }
}
