<picture>
    <source type="image/avif" srcset="{{ $image->srcset('avif') }}" sizes="{{ $sizes }}">
    <img
        src="{{ $image->fallback() }}"
        srcset="{{ $image->srcset('webp') }}"
        sizes="{{ $sizes }}"
        width="{{ $image->width }}"
        height="{{ $image->height }}"
        alt="{{ $alt }}"
        @if ($priority)
            loading="eager" fetchpriority="high" decoding="sync"
        @elseif ($eager)
            loading="eager" decoding="async"
        @else
            loading="lazy" decoding="async"
        @endif
        {{ $attributes }}
    >
</picture>
