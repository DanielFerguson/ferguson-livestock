<?php

namespace App\Support;

final readonly class BoxDetails
{
    /**
     * @param  list<string>  $contents
     */
    public function __construct(
        public int $weightKg,
        public array $contents,
        public string $bestFor,
        public string $freezerGuidance,
        public int $perKgPrice,
    ) {}
}
