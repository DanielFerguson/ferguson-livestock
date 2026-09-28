<?php

namespace App\Support;

final readonly class Product
{
    /**
     * @param  'box'|'extra'|'delivery'  $type
     */
    public function __construct(
        public string $slug,
        public string $name,
        public string $description,
        public string $type,
        public int $price,
        public ?BoxDetails $box = null,
    ) {}
}
