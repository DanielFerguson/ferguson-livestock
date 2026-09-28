<?php

namespace App\Support;

final readonly class ResponsiveImage
{
    /**
     * @param  array<string, array<int, string>>  $files  URLs keyed by format, then width.
     */
    public function __construct(
        public int $width,
        public int $height,
        private array $files,
    ) {}

    public function srcset(string $format): string
    {
        return collect($this->files[$format] ?? [])
            ->map(fn (string $url, int $width) => "{$url} {$width}w")
            ->implode(', ');
    }

    public function fallback(): string
    {
        return $this->files['webp'][$this->width] ?? '';
    }
}
