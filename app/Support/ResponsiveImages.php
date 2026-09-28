<?php

namespace App\Support;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Describes the AVIF/WebP variants that scripts/build-images.mjs generates from resources/images/variants.json.
 *
 * @phpstan-type VariantConfig array{source: string, widths: non-empty-list<int>, aspect?: string, quality?: array{avif?: int, webp?: int}}
 */
final class ResponsiveImages
{
    public const string GENERATED_DIRECTORY = 'resources/images/generated';

    /** @var array<string, ResponsiveImage> */
    private array $resolved = [];

    /**
     * @param  array<string, VariantConfig>  $variants
     * @param  Closure(string): string  $url  Resolves a generated file's path (relative to the project root) to its public URL.
     */
    public function __construct(
        private readonly array $variants,
        private readonly string $sourceDirectory,
        private readonly Closure $url,
    ) {}

    public function get(string $name): ResponsiveImage
    {
        return $this->resolved[$name] ??= $this->resolve($name);
    }

    private function resolve(string $name): ResponsiveImage
    {
        $variant = $this->variants[$name] ?? throw new InvalidArgumentException("Unknown responsive image [{$name}].");

        $widths = $variant['widths'];
        sort($widths);
        $largest = max($widths);

        [$ratioWidth, $ratioHeight] = isset($variant['aspect'])
            ? array_map(intval(...), explode(':', $variant['aspect']))
            : $this->sourceDimensions($variant['source']);

        $files = [];
        foreach (['avif', 'webp'] as $format) {
            foreach ($widths as $width) {
                $files[$format][$width] = ($this->url)(self::GENERATED_DIRECTORY."/{$name}-{$width}.{$format}");
            }
        }

        return new ResponsiveImage(
            width: $largest,
            height: (int) round($largest * $ratioHeight / $ratioWidth),
            files: $files,
        );
    }

    /**
     * @return array{int, int}
     */
    private function sourceDimensions(string $source): array
    {
        $size = @getimagesize($this->sourceDirectory.'/'.$source);

        if ($size === false) {
            throw new RuntimeException("Cannot read the dimensions of [{$source}].");
        }

        return [$size[0], $size[1]];
    }
}
