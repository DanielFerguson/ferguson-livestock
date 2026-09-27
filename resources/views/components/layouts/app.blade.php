@props([
    'title',
    'description' => null,
    'image' => null,
    'imageAlt' => null,
    'canonical' => null,
    'robots' => 'index, follow',
    // One schema.org object, or a list of them (one script tag each).
    'structuredData' => null,
    // Font aliases from vite.config.js; the confirmation pages add 'caveat'.
    'fonts' => ['source-sans', 'cormorant'],
])

@php
    $siteUrl = rtrim(config('shop.url'), '/');
    $path = request()->path();

    $description ??= config('shop.brand.short_description');
    $canonical ??= $siteUrl.($path === '/' ? '/' : '/'.$path);
    $usesDefaultImage = $image === null;
    $image ??= config('shop.og_image.path');
    $image = str_starts_with($image, 'http') ? $image : $siteUrl.$image;
    $imageAlt ??= config('shop.og_image.alt');

    $schemas = match (true) {
        $structuredData === null => [],
        array_is_list($structuredData) => $structuredData,
        default => [$structuredData],
    };
@endphp
<!DOCTYPE html>
<html lang="en-AU">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $canonical }}">
    <meta name="robots" content="{{ $robots }}">
    <meta name="author" content="{{ config('shop.name') }}">

    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="manifest" href="/site.webmanifest">
    <meta name="theme-color" content="#1a2e1a">
    <meta name="apple-mobile-web-app-title" content="{{ config('shop.name') }}">

    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:image" content="{{ $image }}">
    <meta property="og:image:alt" content="{{ $imageAlt }}">
    @if ($usesDefaultImage)
        <meta property="og:image:width" content="{{ config('shop.og_image.width') }}">
        <meta property="og:image:height" content="{{ config('shop.og_image.height') }}">
    @endif
    <meta property="og:site_name" content="{{ config('shop.name') }}">
    <meta property="og:locale" content="en_AU">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $image }}">
    <meta name="twitter:image:alt" content="{{ $imageAlt }}">

    <meta name="geo.region" content="AU-VIC">
    <meta name="geo.placename" content="{{ config('shop.location.locality') }}">

    @fonts($fonts)
    @vite('resources/css/app.css')

    @foreach ($schemas as $schema)
        <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_THROW_ON_ERROR) !!}</script>
    @endforeach
</head>
<body class="overflow-x-hidden bg-cream font-sans leading-relaxed text-gray-800">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-100 focus:rounded-lg focus:bg-forest focus:px-4 focus:py-2 focus:text-cream">
        Skip to main content
    </a>

    <main id="main-content">
        {{ $slot }}
    </main>
</body>
</html>
