<?php

namespace App\Support;

use App\Enums\ProductType;

/**
 * A product as the marketing pages show it, priced from the featured drop.
 */
final readonly class CatalogueEntry
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $description,
        public ProductType $type,
        /** In cents; null when no drop has priced this product yet */
        public ?int $price,
        public ?BoxDetails $box = null,
    ) {}
}
