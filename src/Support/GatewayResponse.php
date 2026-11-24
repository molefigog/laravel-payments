<?php

namespace Molefigog\Payments\Support;

final class GatewayResponse
{
    public const PENDING = 'pending';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    /** What the client should do next: none | otp | poll | redirect | html */
    public function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
        public readonly ?string $txnId = null,
        public readonly ?string $conversationId = null,
        public readonly string $action = 'none',
        public readonly ?string $redirectUrl = null,
        public readonly ?string $html = null,
        public readonly array $data = [],
        public readonly ?string $code = null,
    ) {
    }

    public static function pending(?string $message = null, string $action = 'none', array $data = [], ?string $txnId = null, ?string $conversationId = null, ?string $redirectUrl = null, ?string $html = null, ?string $code = null): self
    {
        return new self(self::PENDING, $message, $txnId, $conversationId, $action, $redirectUrl, $html, $data, $code);
    }

    public static function completed(?string $message = null, array $data = [], ?string $txnId = null, ?string $conversationId = null): self
    {
        return new self(self::COMPLETED, $message, $txnId, $conversationId, 'none', null, null, $data);
    }

    public static function failed(?string $message = null, array $data = [], ?string $code = null, ?string $txnId = null): self
    {
        return new self(self::FAILED, $message, $txnId, null, 'none', null, null, $data, $code);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::FAILED;
    }
}
