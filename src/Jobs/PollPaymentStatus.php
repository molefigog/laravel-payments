<?php

namespace Molefigog\Payments\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Molefigog\Payments\GatewayManager;
use Molefigog\Payments\PaymentService;
use Molefigog\Payments\Support\GatewayResponse;
use Throwable;

class PollPaymentStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public string $reference, public int $attempt = 1)
    {
        $this->onConnection(config('payments.polling.connection'));
        $this->onQueue(config('payments.polling.queue'));
    }

    public function handle(PaymentService $payments, GatewayManager $gateways): void
    {
        $result = $payments->refresh($this->reference);

        if ($result === null || $result->status !== GatewayResponse::PENDING) {
            return;
        }

        if ($this->attempt >= (int) config('payments.polling.max_attempts', 40)) {
            return; // stop polling; `payments:reconcile` can pick it up later
        }

        $first = $result->payments->first();
        $interval = 15;
        try {
            $interval = $gateways->driver((string) $first->gateway, true)->pollInterval() ?? 15;
        } catch (Throwable) {
        }

        static::dispatch($this->reference, $this->attempt + 1)->delay(now()->addSeconds(max(1, $interval)));
    }
}
