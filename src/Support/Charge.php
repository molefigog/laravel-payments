<?php

namespace Molefigog\Payments\Support;

use Illuminate\Support\Collection;
use Molefigog\Payments\Models\Payment;

/** A read-only view of one charge (all payment rows that share a reference). Passed to gateways. */
final class Charge
{
    public function __construct(
        public readonly string $reference,
        public readonly string $gateway,
        public readonly float $amount,
        public readonly string $currency,
        public readonly ?string $msisdn,
        public readonly ?string $description,
        public readonly int|string|null $userId,
        public readonly array $options,
        public readonly ?string $txnId,
        public readonly ?string $conversationId,
        public readonly array $raw,
        public readonly ?\DateTimeInterface $createdAt,
        public readonly Collection $payments,
    ) {
    }

    public static function fromPayments(Collection $payments): self
    {
        /** @var Payment $first */
        $first = $payments->first();
        $raw = json_decode((string) $first->raw_response, true);

        return new self(
            reference: (string) $first->reference,
            gateway: (string) $first->gateway,
            amount: round((float) $payments->sum('amount'), 2),
            currency: (string) ($first->currency ?: config('payments.currency', 'LSL')),
            msisdn: $first->msisdn,
            description: $first->description,
            userId: $first->user_id,
            options: (array) (($first->meta ?? [])['options'] ?? []),
            txnId: $first->txn_id,
            conversationId: $first->conversation_id,
            raw: is_array($raw) ? $raw : [],
            createdAt: $first->created_at,
            payments: $payments,
        );
    }

    public function ageSeconds(): int
    {
        return $this->createdAt ? (int) abs(now()->diffInSeconds($this->createdAt, true)) : PHP_INT_MAX;
    }

    /** True once the provider has given us its own id (we start out with our reference in txn_id). */
    public function hasProviderTxn(): bool
    {
        return $this->txnId !== null && $this->txnId !== '' && $this->txnId !== $this->reference;
    }
}
