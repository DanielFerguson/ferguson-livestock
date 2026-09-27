@php
    /** @var list<array{label: string, route: string}> $links */
    $links = config('shop.navigation.primary');

    // Mid-purchase pages point the call to action home instead of back into the shop.
    $transactional = request()->routeIs('order', 'order-confirmed', 'thank-you');
@endphp

<header class="sticky top-0 z-50 border-b border-forest/10 bg-cream">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
        <a href="{{ route('home') }}" class="flex min-w-0 items-center gap-3 text-forest no-underline" aria-label="{{ config('shop.name') }} home">
            <x-picture name="logo" alt="" sizes="44px" eager class="h-11 w-11 shrink-0 rounded-full object-cover" />
            <span class="min-w-0">
                <span class="block font-display text-xl leading-none font-semibold tracking-tight sm:text-2xl">{{ config('shop.name') }}</span>
                <span class="eyebrow mt-1 hidden text-sage sm:block">{{ config('shop.location.locality') }}, Victoria</span>
            </span>
        </a>

        <nav class="hidden items-center gap-6 lg:flex" aria-label="Primary">
            @foreach ($links as $link)
                <a
                    href="{{ route($link['route']) }}"
                    @class([
                        'text-sm font-semibold no-underline transition-colors hover:text-sage',
                        'text-sage' => request()->routeIs($link['route']),
                        'text-forest' => ! request()->routeIs($link['route']),
                    ])
                    @if (request()->routeIs($link['route'])) aria-current="page" @endif
                >{{ $link['label'] }}</a>
            @endforeach
        </nav>

        <div class="flex shrink-0 items-center gap-2">
            @if ($transactional)
                <x-button :href="route('home')" class="px-4 sm:px-5">Home</x-button>
            @else
                <x-button :href="route('order')" class="px-4 sm:px-5">Shop</x-button>
            @endif

            {{-- <details> opens and closes without JavaScript on every browser, including older phones. --}}
            <details class="group relative lg:hidden">
                <summary class="flex min-h-12 cursor-pointer list-none items-center border border-forest/25 px-4 text-sm font-semibold text-forest [&::-webkit-details-marker]:hidden">
                    <span class="group-open:hidden">Menu</span>
                    <span class="hidden group-open:inline">Close</span>
                </summary>
                <nav class="absolute top-[calc(100%+0.75rem)] right-0 grid w-60 border border-forest/10 bg-cream p-2 shadow-xl" aria-label="Mobile">
                    @foreach ($links as $link)
                        <a
                            href="{{ route($link['route']) }}"
                            class="px-4 py-3 text-base font-semibold text-forest no-underline hover:bg-cream-dark hover:text-sage"
                            @if (request()->routeIs($link['route'])) aria-current="page" @endif
                        >{{ $link['label'] }}</a>
                    @endforeach
                </nav>
            </details>
        </div>
    </div>
</header>
