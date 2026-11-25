<?php

namespace Molefigog\Payments\Console;

use Illuminate\Console\Command;
use Molefigog\Payments\Models\PaymentGateway;
use Molefigog\Payments\Support\Models;

class SeedGatewaysCommand extends Command
{
    protected $signature = 'payments:seed-gateways';

    protected $description = 'Create payment_gateways rows (name, slug, driver, enabled) from config/payments.php. Credentials stay in .env until you save them in the DB.';

    public function handle(): int
    {
        $created = 0;

        foreach ((array) config('payments.gateways', []) as $slug => $cfg) {
            $row = Models::gateway()::query()->firstOrCreate(['slug' => $slug], [
                'name' => $cfg['name'] ?? $slug,
                'driver' => $cfg['driver'] ?? $slug,
                'enabled' => (bool) ($cfg['enabled'] ?? true),
                'currency' => $cfg['currency'] ?? null,
            ]);

            if ($row->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->info("Seeded $created gateway row(s).");

        return self::SUCCESS;
    }
}
