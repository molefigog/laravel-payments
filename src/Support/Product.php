<?php

namespace Molefigog\Payments\Support;

final class Product
{
    public function __construct(
        public readonly string|int $id,
        public readonly string $title,
        public readonly float $amount,
        public readonly string|int|null $payeeId = null,
        public readonly array $meta = [],
    ) {
    }
}
