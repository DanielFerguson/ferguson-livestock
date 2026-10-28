{{-- Used for errors where the rest of the site may be what has broken, so this page needs nothing from the database. --}}
<!doctype html>
<html lang="en-AU">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, follow">
    <title>{{ $title }} | Ferguson Livestock</title>
    <style>
        body { margin: 0; background: #f5f2ea; color: #1b2d1d; font-family: Georgia, 'Times New Roman', serif; text-align: center; }
        main { max-width: 36rem; margin: 0 auto; padding: 6rem 1.5rem; }
        p.eyebrow { font: 600 0.8rem/1 system-ui, sans-serif; letter-spacing: 0.15em; text-transform: uppercase; color: #5c7a5f; }
        h1 { font-size: clamp(2.25rem, 8vw, 3.5rem); line-height: 1.1; margin: 1rem 0; }
        p { font: 1.125rem/1.7 system-ui, sans-serif; color: #374151; }
        a { display: inline-block; margin-top: 1.5rem; padding: 0.9rem 1.75rem; background: #1b2d1d; color: #fff; font: 600 1rem system-ui, sans-serif; text-decoration: none; }
    </style>
</head>
<body>
    <main>
        <p class="eyebrow">Ferguson Livestock · {{ $eyebrow }}</p>
        <h1>{{ $heading }}</h1>
        <p>{{ $message }}</p>
        <a href="{{ url('/') }}">Back home</a>
    </main>
</body>
</html>
