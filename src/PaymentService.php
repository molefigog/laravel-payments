<?php

namespace Molefigog\Payments;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Molefigog\Payments\Contracts\ProductResolver;
use Molefigog\Payments\Events\PaymentCompleted;
use Molefigog\Payments\Events\PaymentFailed;
use Molefigog\Payments\Events\PaymentInitiated;
use Molefigog\Payments\Exceptions\PaymentException;
use Molefigog\Payments\Jobs\PollPaymentStatus;
use Molefigog\Payments\Models\Payment;
use Molefigog\Payments\Support\Charge;
use Molefigog\Payments\Support\ChargeRequest;
use Molefigog\Payments\Support\ChargeResult;
use Molefigog\Payments\Support\GatewayResponse;
use Molefigog\Payments\Support\RedirectGuard;
use Throwable;

class PaymentService
{
    public function __construct(
        protected GatewayManager $gateways,
        protected Dispatcher $events,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Public API
    |--------------------------------------------------------------------------
    */

    public function charge(ChargeRequest $request): ChargeResult
    {
        $gateway = $this->gateways->driver($request->gateway);
        $definition = $this->gateways->definition($request->gateway);
        $currency = $request->currency ?: ($definition['currency'] ?? null) ?: config('payments.currency', 'LSL');

        $lines = $this->resolveLines($request);
        $reference = $this->newReference();

        $options = $request->options;
        if (isset($options['return_url']) && ! RedirectGuard::isAllowed($options['return_url'])) {
            unset($options['return_url']); // never store a redirect target that fails the allow-list
        }

        $rows = DB::transaction(function () use ($lines, $request, $reference, $currency, $options) {
            return collect($lines)->map(fn (array $line) => Payment::create([
                'reference' => $reference,
                'user_id' => $request->userId,
                'product_id' => $line['product_id'],
                'payee_id' => $line['payee_id'],
                'gateway' => $request->gateway,
                'amount' => $line['amount'],
                'currency' => $currency,
                'status' => 'pending',
                'msisdn' => $request->msisdn,
                'txn_id' => $reference,
                'conversation_id' => $reference,
                'description' => $line['title'] ?? $request->description,
                'meta' => [
                    'options' => $options,
                    'product' => $line['meta'],
                    'request' => $request->meta,
                ],
            ]));
        });

        $this->events->dispatch(new PaymentInitiated($rows));

        try {
            $response = $gateway->initiate(Charge::fromPayments($rows));
        } catch (Throwable $e) {
            Log::error('Payment initiate failed', ['reference' => $reference, 'gateway' => $request->gateway, 'error' => $e->getMessage()]);
            $response = GatewayResponse::failed('The payment could not be started. Please try again.', ['error' => $e->getMessage()]);
        }

        return $this->applyAndRespond($reference, $response, $request->gateway);
    }

    /** Second step: CPay OTP, PayPal capture from the JS SDK, etc. */
    public function confirm(string $reference, array $input): ChargeResult
    {
        $rows = $this->rows($reference);

        if ($rows->isEmpty()) {
            throw new PaymentException('Payment not found.');
        }

        $first = $rows->first();

        if ($first->status === 'completed') {
            return $this->result($rows, new GatewayResponse('completed', 'Already processed', $first->txn_id));
        }

        $gateway = $this->gateways->driver((string) $first->gateway, true);

        try {
            $response = $gateway->confirm(Charge::fromPayments($rows), $input);
        } catch (Throwable $e) {
            Log::error('Payment confirm failed', ['reference' => $first->reference, 'error' => $e->getMessage()]);
            $response = GatewayResponse::failed('The payment could not be confirmed.', ['error' => $e->getMessage()]);
        }

        return $this->applyAndRespond((string) $first->reference, $response, (string) $first->gateway);
    }

    /** Ask the provider where a pending payment stands. Safe to call repeatedly. */
    public function refresh(string $reference): ?ChargeResult
    {
        $rows = $this->rows($reference);

        if ($rows->isEmpty()) {
            return null;
        }

        $first = $rows->first();

        if (in_array($first->status, ['completed', 'failed'], true)) {
            return $this->result($rows, new GatewayResponse((string) $first->status, null, $first->txn_id));
        }

        $gateway = $this->gateways->driver((string) $first->gateway, true);
        $charge = Charge::fromPayments($rows);

        try {
            $response = $gateway->status($charge);
        } catch (Throwable $e) {
            Log::warning('Payment status check failed', ['reference' => $first->reference, 'error' => $e->getMessage()]);
            $response = GatewayResponse::pending('Status check failed; will retry.');
        }

        $timeout = $gateway->timeoutSeconds();
        if ($response->isPending() && $timeout !== null && $charge->ageSeconds() > $timeout) {
            $response = GatewayResponse::failed('Payment timed out.', $response->data, 'timeout');
        }

        return $this->applyAndRespond((string) $first->reference, $response, (string) $first->gateway);
    }

    /** Provider callback or browser return. Returns null when no payment matches. */
    public function handleCallback(string $gatewaySlug, array $payload): ?ChargeResult
    {
        $gateway = $this->gateways->driver($gatewaySlug, true);
        $id = $gateway->callbackReference($payload);

        if ($id === null) {
            Log::warning('Payment callback without reference', ['gateway' => $gatewaySlug]);

            return null;
        }

        $rows = $this->rows($id);

        if ($rows->isEmpty() || $rows->first()->gateway !== $gatewaySlug) {
            Log::warning('Payment callback for unknown payment', ['gateway' => $gatewaySlug, 'id' => $id]);

            return null;
        }

        if ($rows->first()->status === 'completed') {
            return $this->result($rows, new GatewayResponse('completed', 'Already processed', $rows->first()->txn_id));
        }

        try {
            $response = $gateway->callback(Charge::fromPayments($rows), $payload);
        } catch (Throwable $e) {
            Log::error('Payment callback failed', ['gateway' => $gatewaySlug, 'error' => $e->getMessage()]);
            $response = GatewayResponse::pending('Callback could not be processed.');
        }

        return $this->applyAndRespond((string) $rows->first()->reference, $response, $gatewaySlug);
    }

    /**
     * Find the rows of a charge by our reference, or by a provider transaction/conversation id.
     *
     * @return Collection<int, Payment>
     */
    public function rows(string $idOrReference): Collection
    {
        $first = Payment::query()
            ->where('reference', $idOrReference)
            ->orWhere('txn_id', $idOrReference)
            ->orWhere('conversation_id', $idOrReference)
            ->orderBy('id')
            ->first();

        if (! $first || ! $first->reference) {
            return collect();
        }

        return Payment::query()->where('reference', $first->reference)->orderBy('id')->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /** @return array<int, array{product_id:?string,title:?string,amount:float,payee_id:int|string|null,meta:array}> */
    protected function resolveLines(ChargeRequest $request): array
    {
        $resolverClass = config('payments.resolver');

        if ($resolverClass) {
            if ($request->productIds === []) {
                throw new PaymentException('At least one product is required.');
            }

            /** @var ProductResolver $resolver */
            $resolver = app($resolverClass);
            $products = $resolver->resolve($request->productIds, ['user_id' => $request->userId, 'meta' => $request->meta]);

            if ($products === []) {
                throw new PaymentException('No products could be resolved.');
            }

            $lines = array_map(fn ($p) => [
                'product_id' => (string) $p->id,
                'title' => $p->title,
                'amount' => round($p->amount, 2),
                'payee_id' => $p->payeeId,
                'meta' => $p->meta,
            ], array_values($products));
        } else {
            // No resolver: the caller supplies the total. Fine for server-to-server use,
            // but a browser/mobile client can then choose its own price. Set a resolver.
            if ($request->amount === null || $request->amount <= 0) {
                throw new PaymentException('A positive amount is required.');
            }

            $ids = $request->productIds ?: [null];
            $cents = (int) round($request->amount * 100);
            $each = intdiv($cents, count($ids));
            $lines = [];

            foreach ($ids as $i => $id) {
                $lineCents = $i === count($ids) - 1 ? $cents - $each * (count($ids) - 1) : $each;
                $lines[] = [
                    'product_id' => $id,
                    'title' => $request->description,
                    'amount' => (float) ($lineCents / 100),
                    'payee_id' => null,
                    'meta' => [],
                ];
            }
        }

        if (round(array_sum(array_column($lines, 'amount')), 2) <= 0) {
            throw new PaymentException('The total amount must be greater than zero.');
        }

        return $lines;
    }

    protected function newReference(): string
    {
        return 'PAY'.now()->format('ymdHis').strtoupper(Str::random(5));
    }

    protected function applyAndRespond(string $reference, GatewayResponse $response, string $gatewaySlug): ChargeResult
    {
        [$rows, $transition] = $this->apply($reference, $response);

        if ($transition === 'completed') {
            $this->events->dispatch(new PaymentCompleted($rows));
        } elseif ($transition === 'failed') {
            $this->events->dispatch(new PaymentFailed($rows, $response->message));
        }

        if ($response->isPending() && $response->action === 'poll') {
            $this->schedulePoll($reference, $gatewaySlug);
        }

        return $this->result($rows, $response);
    }

    /**
     * Apply a gateway response inside a locked transaction. Completed is final, so a
     * retried webhook can never fire PaymentCompleted twice.
     *
     * @return array{0: Collection<int, Payment>, 1: ?string}  rows and the transition that happened (completed|failed|null)
     */
    protected function apply(string $reference, GatewayResponse $response): array
    {
        return DB::transaction(function () use ($reference, $response) {
            $rows = Payment::query()->where('reference', $reference)->orderBy('id')->lockForUpdate()->get();

            if ($rows->isEmpty()) {
                return [$rows, null];
            }

            $old = (string) $rows->first()->status;

            if ($old === 'completed') {
                return [$rows, null];
            }

            $new = $response->status;
            if ($old === 'failed' && $new !== 'completed') {
                $new = 'failed';
            }

            $update = ['status' => $new];

            if ($response->txnId) {
                $update['txn_id'] = $response->txnId;
            }
            if ($response->conversationId) {
                $update['conversation_id'] = $response->conversationId;
            }
            if ($response->data !== []) {
                $update['raw_response'] = json_encode($response->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($new === 'completed') {
                $update['paid_at'] = now();
            }

            Payment::query()->where('reference', $reference)->update($update);

            $fresh = Payment::query()->where('reference', $reference)->orderBy('id')->get();

            $transition = null;
            if ($new === 'completed') {
                $transition = 'completed';
            } elseif ($new === 'failed' && $old !== 'failed') {
                $transition = 'failed';
            }

            return [$fresh, $transition];
        });
    }

    protected function schedulePoll(string $reference, string $gatewaySlug): void
    {
        if (config('queue.default') === 'sync' && ! config('payments.polling.allow_sync_queue')) {
            return; // delayed jobs can't work here; clients poll GET /status/{reference}
        }

        try {
            $interval = $this->gateways->driver($gatewaySlug, true)->pollInterval() ?? 15;
            PollPaymentStatus::dispatch($reference, 1)->delay(now()->addSeconds(max(1, $interval)));
        } catch (Throwable $e) {
            Log::warning('Could not schedule payment polling', ['reference' => $reference, 'error' => $e->getMessage()]);
        }
    }

    protected function result(Collection $rows, GatewayResponse $response): ChargeResult
    {
        $first = $rows->first();

        return new ChargeResult(
            reference: (string) ($first?->reference ?? ''),
            status: (string) ($first?->status ?? $response->status),
            action: $response->action,
            message: $response->message,
            redirectUrl: $response->redirectUrl,
            html: $response->html,
            txnId: $first?->txn_id,
            payments: $rows,
        );
    }
}
