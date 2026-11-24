<?php

namespace Molefigog\Payments\Contracts;

use Molefigog\Payments\Support\Product;

/**
 * Turns product ids into server-trusted prices. Implement this in the host app and
 * set `payments.resolver` to the class name. The package never touches your product tables.
 */
interface ProductResolver
{
    /**
     * @param  array<int, string>  $productIds
     * @param  array{user_id: int|string|null, meta: array}  $context
     * @return array<int, Product>  One Product per id, in the same order. Throw if an id is unknown.
     */
    public function resolve(array $productIds, array $context = []): array;
}
