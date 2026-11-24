<?php

namespace Molefigog\Payments\Console;

use Illuminate\Console\Command;
use Molefigog\Payments\Models\Payment;
use Molefigog\Payments\PaymentService;

class ReconcileCommand extends Command
{
    protected $signature = 'payments:reconcile {--minutes=1440 : Look back this many minutes}';

    protected $description = 'Ask providers about payments still pending (e.g. after polling gave up). Safe to schedule.';

    public function handle(PaymentService $payments): int
    {
        $references = Payment::query()
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subMinutes((int) $this->option('minutes')))
            ->whereNotNull('reference')
            ->distinct()
            ->pluck('reference');

        foreach ($references as $reference) {
            $result = $payments->refresh((string) $reference);
            $this->line("$reference => ".($result?->status ?? 'not found'));
        }

        $this->info($references->count().' pending payment(s) checked.');

        return self::SUCCESS;
    }
}
