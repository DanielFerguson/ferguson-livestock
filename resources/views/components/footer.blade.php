@php
    /** @var list<array{label: string, route: string}> $explore */
    $explore = config('shop.navigation.primary');

    /** @var list<array{label: string, route: string, fragment?: string}> $information */
    $information = config('shop.navigation.information');

    $linkClasses = 'text-sm text-cream/80 no-underline transition-colors hover:text-mint-light';
@endphp

<footer class="bg-forest py-14 text-cream">
    <div class="mx-auto max-w-6xl px-6">
        <div class="mb-12 grid gap-10 md:grid-cols-2 lg:grid-cols-[1.4fr_0.8fr_0.8fr_1.1fr]">
            <div>
                <p class="mb-4 font-display text-3xl font-semibold">{{ config('shop.name') }}</p>
                <p class="max-w-sm text-sm leading-relaxed text-cream/80">{{ config('shop.brand.promise') }}</p>
                <p class="mt-4 text-sm text-cream/70">
                    {{ config('shop.location.locality') }}, {{ config('shop.location.region') }} · {{ config('shop.location.proximity') }}
                </p>
            </div>

            <div>
                <h2 class="eyebrow mb-4 text-mint-light">Explore</h2>
                <ul class="space-y-3">
                    @foreach ($explore as $link)
                        <li><a href="{{ route($link['route']) }}" class="{{ $linkClasses }}">{{ $link['label'] }}</a></li>
                    @endforeach
                </ul>
            </div>

            <div>
                <h2 class="eyebrow mb-4 text-mint-light">Information</h2>
                <ul class="space-y-3">
                    @foreach ($information as $link)
                        <li>
                            <a href="{{ route($link['route']).(isset($link['fragment']) ? '#'.$link['fragment'] : '') }}" class="{{ $linkClasses }}">{{ $link['label'] }}</a>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div>
                <h2 class="eyebrow mb-4 text-mint-light">Talk to the farmers</h2>
                <div class="space-y-3 text-sm">
                    <a href="tel:{{ config('shop.phone.international') }}" class="block {{ $linkClasses }}">{{ config('shop.phone.display') }}</a>
                    <a href="mailto:{{ config('shop.email') }}" class="block break-words {{ $linkClasses }}">{{ config('shop.email') }}</a>
                    <a href="{{ config('shop.social.facebook') }}" target="_blank" rel="noopener noreferrer" class="block {{ $linkClasses }}">Facebook</a>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-3 border-t border-cream/15 pt-7 text-sm text-cream/70 sm:flex-row sm:items-center sm:justify-between">
            <p>&copy; {{ now(config('shop.timezone'))->year }} {{ config('shop.name') }}. All rights reserved.</p>
            <p>{{ config('shop.brand.tagline') }}</p>
        </div>
    </div>
</footer>
