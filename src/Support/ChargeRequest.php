<?php

namespace Molefigog\Payments\Support;

final class ChargeRequest
{
    /**
     * @param  array<int, string>  $productIds
     * @param  array  $options  Gateway options: return_url, email, method (cpay: mobile|card) ...
     */
    public function __construct(
        public readonly string $gateway,
        public readonly array $productIds = [],
        public readonly ?float $amount = null,
        public readonly ?string $msisdn = null,
        public readonly ?string $description = null,
        public readonly ?string $currency = null,
        public readonly int|string|null $userId = null,
        public readonly array $meta = [],
        public readonly array $options = [],
    ) {
    }

    public static function make(string $gateway, mixed $products = null, array $data = [], int|string|null $userId = null): self
    {
        return new self(
            gateway: $gateway,
            productIds: self::normalizeIds($products),
            amount: isset($data['amount']) && is_numeric($data['amount']) ? (float) $data['amount'] : null,
            msisdn: isset($data['msisdn']) ? (string) $data['msisdn'] : null,
            description: $data['description'] ?? null,
            currency: $data['currency'] ?? null,
            userId: $userId,
            meta: (array) ($data['meta'] ?? []),
            options: array_filter([
                'return_url' => $data['return_url'] ?? null,
                'email' => $data['email'] ?? null,
                'method' => $data['method'] ?? null,
                'client' => $data['client'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
        );
    }

    /** Accepts 5, "5", "5,6,7", [5,6], null. */
    public static function normalizeIds(mixed $products): array
    {
        if ($products === null || $products === '' || $products === []) {
            return [];
        }

        if (is_string($products) && str_contains($products, ',')) {
            $products = explode(',', $products);
        }

        $ids = [];
        foreach ((array) $products as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
