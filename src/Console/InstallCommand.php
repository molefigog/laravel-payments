<?php

namespace Molefigog\Payments\Console;

use Illuminate\Console\Command;
use Molefigog\Payments\Support\StubPublisher;

class InstallCommand extends Command
{
    protected $signature = 'payments:install
        {--all : Publish everything}
        {--config : Publish config/payments.php}
        {--migrations : Copy the migrations into database/migrations}
        {--models : Create App\Models\Payment and PaymentGateway extending the package models}
        {--listener : Create a PaymentCompleted listener stub}
        {--resolver : Create a ProductResolver stub (server-side pricing)}
        {--vue : Copy the Vue 3 / Quasar composable and PaymentDialog}
        {--vue-path= : Where the Vue files go (default resources/js/payments; use ../my-quasar-app/src/payments for a separate app)}
        {--blade : Copy the Blade checkout component and its JS}
        {--force : Overwrite files that already exist}';

    protected $description = 'Choose what to publish: config, migrations, model/listener/resolver stubs and frontend (Vue/Quasar, Blade).';

    /** @var array<string,string> */
    private const CHOICES = [
        'config' => 'Config (config/payments.php)',
        'migrations' => 'Migrations (copy into database/migrations)',
        'models' => 'Model stubs (App\Models\Payment, PaymentGateway: extend the package models)',
        'listener' => 'Listener stub (runs when a payment completes)',
        'resolver' => 'Product resolver stub (server-side pricing)',
        'vue' => 'Vue 3 / Quasar: usePayment() + PaymentDialog',
        'blade' => 'Blade: <x-payments.checkout> (also works inside Livewire pages)',
    ];

    private StubPublisher $publisher;

    public function handle(): int
    {
        $this->publisher = new StubPublisher((bool) $this->option('force'));
        $selected = $this->selection();

        if ($selected === []) {
            $this->components->warn('Nothing selected.');

            return self::SUCCESS;
        }

        foreach (array_keys(self::CHOICES) as $key) {
            if (in_array($key, $selected, true)) {
                $this->components->info('Publishing: '.$key);
                $this->{'publish'.ucfirst($key)}();
            }
        }

        $this->newLine();
        $this->components->info('Done. Next: php artisan migrate');

        return self::SUCCESS;
    }

    /** @return array<int,string> */
    private function selection(): array
    {
        if ($this->option('all')) {
            return array_keys(self::CHOICES);
        }

        $flagged = array_values(array_filter(array_keys(self::CHOICES), fn ($key) => (bool) $this->option($key)));
        if ($flagged !== []) {
            return $flagged;
        }

        if (! $this->input->isInteractive()) {
            return ['config', 'migrations'];
        }

        if (function_exists('Laravel\Prompts\multiselect')) {
            return array_values(\Laravel\Prompts\multiselect(
                label: 'What should be published?',
                options: self::CHOICES,
                default: ['config', 'migrations'],
                scroll: 8,
                hint: 'Space to tick, Enter to confirm. Re-run any time; existing files are never overwritten without --force.',
            ));
        }

        $picked = $this->choice('What should be published? (comma separated)', array_values(self::CHOICES), null, null, true);

        return array_values(array_keys(array_intersect(self::CHOICES, (array) $picked)));
    }

    private function publishConfig(): void
    {
        $this->callSilently('vendor:publish', ['--tag' => 'payments-config', '--force' => (bool) $this->option('force')]);
        $this->report(config_path('payments.php'), is_file(config_path('payments.php')) ? 'ready' : 'missing');
    }

    private function publishMigrations(): void
    {
        $this->callSilently('vendor:publish', ['--tag' => 'payments-migrations', '--force' => (bool) $this->option('force')]);
        $this->report(database_path('migrations'), 'copied (existing files kept)');
    }

    private function publishModels(): void
    {
        $ns = $this->laravel->getNamespace();   // "App\"
        $stubs = $this->stubs();

        $targets = ['Payment' => 'payment', 'PaymentGateway' => 'gateway'];

        foreach ($targets as $class => $configKey) {
            $path = app_path("Models/$class.php");
            $this->report($path, $this->publisher->file("$stubs/$class.stub", $path, ['rootNamespace' => $ns]));

            $contents = is_file($path) ? (string) file_get_contents($path) : '';
            if (! str_contains($contents, 'Molefigog\\Payments\\Models')) {
                $this->components->warn("{$ns}Models\\$class already exists and does not extend the package model. "
                    ."Make it extend Molefigog\\Payments\\Models\\$class, then set payments.models.$configKey.");

                continue;
            }

            $this->patchConfig(
                "'$configKey' => Molefigog\\Payments\\Models\\$class::class,",
                "'$configKey' => {$ns}Models\\$class::class,",
                "models.$configKey => {$ns}Models\\$class"
            );
        }
    }

    private function publishListener(): void
    {
        $ns = $this->laravel->getNamespace();
        $path = app_path('Listeners/HandlePaymentCompleted.php');
        $this->report($path, $this->publisher->file($this->stubs().'/HandlePaymentCompleted.stub', $path, ['rootNamespace' => $ns]));
    }

    private function publishResolver(): void
    {
        $ns = $this->laravel->getNamespace();
        $path = app_path('Payments/AppProductResolver.php');
        $this->report($path, $this->publisher->file($this->stubs().'/AppProductResolver.stub', $path, ['rootNamespace' => $ns]));

        $this->patchConfig(
            "'resolver' => null,",
            "'resolver' => {$ns}Payments\\AppProductResolver::class,",
            "resolver => {$ns}Payments\\AppProductResolver"
        );
        $this->components->warn('Implement AppProductResolver::resolve(): until then every charge fails safely.');
    }

    private function publishVue(): void
    {
        $default = 'resources/js/payments';
        $path = (string) ($this->option('vue-path') ?: '');

        if ($path === '' && $this->input->isInteractive() && ! $this->option('all') && function_exists('Laravel\Prompts\text')) {
            $path = \Laravel\Prompts\text(
                label: 'Where should the Vue files go?',
                default: $default,
                hint: 'Quasar app in a separate project? e.g. ../Musik-Frontend2/src/payments'
            );
        }

        $dest = StubPublisher::resolvePath($path !== '' ? $path : $default, base_path());
        $js = $this->packageRoot().'/resources/js';

        foreach (array_merge(
            $this->publisher->directory("$js/core", "$dest/core"),
            $this->publisher->directory("$js/vue", "$dest/vue"),
        ) as $file => $status) {
            $this->report($file, $status);
        }

        $this->line("  Use it:  import PaymentDialog from '<alias>/payments/vue/PaymentDialog.vue'");
        $this->line("  or:      import { usePayment } from '<alias>/payments/vue/usePayment.js'   (needs vue; the dialog uses Quasar)");
    }

    private function publishBlade(): void
    {
        $root = $this->packageRoot();

        $this->report(
            $view = resource_path('views/components/payments/checkout.blade.php'),
            $this->publisher->file("$root/resources/views/components/payments/checkout.blade.php", $view)
        );

        foreach (['payments-core.global.js', 'payments-blade.js'] as $asset) {
            $this->report(
                $target = public_path("vendor/payments/$asset"),
                $this->publisher->file("$root/resources/dist/$asset", $target)
            );
        }

        $this->line('  Use it:  <x-payments.checkout :product-ids="[$item->id]" label="Buy now" />');
    }

    private function patchConfig(string $search, string $replace, string $label): void
    {
        $file = config_path('payments.php');

        if (! is_file($file)) {
            $this->components->warn("Publish the config too, then set: $label");

            return;
        }

        $contents = (string) file_get_contents($file);

        if (str_contains($contents, $replace)) {
            return;
        }

        if (! str_contains($contents, $search)) {
            $this->components->warn("Could not update config/payments.php automatically. Set: $label");

            return;
        }

        file_put_contents($file, str_replace($search, $replace, $contents));
        $this->report($file, 'updated: '.$label);
    }

    private function report(string $path, string $status): void
    {
        $this->components->twoColumnDetail($this->relative($path), $status);
    }

    private function relative(string $path): string
    {
        $base = rtrim(base_path(), '/\\');

        return str_starts_with($path, $base) ? ltrim(substr($path, strlen($base)), '/\\') : $path;
    }

    private function stubs(): string
    {
        return $this->packageRoot().'/stubs';
    }

    private function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
