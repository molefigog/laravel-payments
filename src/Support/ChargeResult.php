<?php

namespace Molefigog\Payments\Support;

use Illuminate\Support\Collection;

final class ChargeResult
{
    public function __construct(
        public readonly string $reference,
        public readonly string $status,
        public readonly string $action,
        public readonly ?string $message,
        public readonly ?string $redirectUrl,
        public readonly ?string $html,
        public readonly ?string $txnId,
        public readonly Collection $payments,
    ) {
    }

    /** "success" means the request was accepted, not that the money moved: check status. */
    public function success(): bool
    {
        return $this->status !== GatewayResponse::FAILED;
    }

    public function isCompleted(): bool
    {
        return $this->status === GatewayResponse::COMPLETED;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success(),
            'status' => $this->status,
            'action' => $this->action,
            'message' => $this->message,
            'reference' => $this->reference,
            'transaction_id' => $this->txnId,
            'redirect_url' => $this->redirectUrl,
            'html' => $this->html,
            'payments' => $this->payments->values()->toArray(),
        ];
    }
}
