{{-- A link or button styled as the site's call to action. Renders <a> when given an href. --}}
@props(['href' => null, 'variant' => 'primary'])

@php
    $variants = [
        'primary' => 'bg-forest text-cream hover:bg-sage',
        'mint' => 'bg-mint text-forest hover:bg-mint-light',
        'outline' => 'border border-forest/25 text-forest hover:border-sage hover:text-sage',
        'outline-light' => 'border border-cream/40 text-cream hover:border-mint-light hover:text-mint-light',
    ];

    $classes = 'inline-flex min-h-12 items-center justify-center gap-2 px-6 py-3 text-center font-semibold no-underline transition-colors '.$variants[$variant];
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'submit', 'class' => $classes.' cursor-pointer']) }}>{{ $slot }}</button>
@endif
