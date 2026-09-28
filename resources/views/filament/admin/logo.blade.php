{{-- The mark is a black square, so it is cropped to a circle as on the public site's header. The name beside it is the visible label. --}}
<span class="fl-brand">
    <img
        src="{{ Vite::asset('resources/images/generated/logo-88.webp') }}"
        srcset="{{ Vite::asset('resources/images/generated/logo-88.webp') }} 1x, {{ asset('android-chrome-192x192.png') }} 2x"
        width="88"
        height="88"
        alt=""
        class="fl-brand-mark"
    >
    <span class="fl-brand-name">{{ config('shop.name') }}</span>
</span>
