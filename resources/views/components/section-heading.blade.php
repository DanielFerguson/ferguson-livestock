{{-- Eyebrow label, heading and optional intro used at the top of each section. Callers set the spacing below it. --}}
@props([
    'eyebrow' => null,
    'level' => 2,
    // 'light' for cream sections, 'dark' for forest sections.
    'tone' => 'light',
    'align' => 'left',
    'intro' => null,
    // 'page' for a subpage's h1, 'lg' for the big homepage sections, 'md' for everything else.
    'size' => 'lg',
])

@php
    $tag = 'h'.$level;
    $centered = $align === 'center';
    $dark = $tone === 'dark';
@endphp

<div {{ $attributes->class(['mx-auto max-w-3xl text-center' => $centered]) }}>
    @if ($eyebrow)
        <p @class(['eyebrow mb-4', 'text-mint-light' => $dark, 'text-sage' => ! $dark])>{{ $eyebrow }}</p>
    @endif

    <{{ $tag }} @class([
        'font-display font-semibold leading-none tracking-tight',
        'text-5xl sm:text-6xl' => $size === 'page',
        'text-4xl sm:text-5xl lg:text-6xl' => $size === 'lg',
        'text-3xl md:text-4xl lg:text-5xl' => $size === 'md',
        'text-cream' => $dark,
        'text-forest' => ! $dark,
    ])>{{ $slot }}</{{ $tag }}>

    @if ($intro)
        <p @class([
            'mt-5 text-lg leading-relaxed',
            'mx-auto max-w-2xl' => $centered,
            'max-w-xl' => ! $centered,
            'text-cream/80' => $dark,
            'text-gray-600' => ! $dark,
        ])>{{ $intro }}</p>
    @endif
</div>
