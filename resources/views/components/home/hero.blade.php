@props(['catalogue'])

@use('App\Support\Money')

<section class="relative isolate flex items-center overflow-hidden py-16 md:py-20 lg:py-24">
    {{-- Barely visible under the overlay, so it ships small and at low quality. --}}
    <x-picture name="hero-cattle" alt="" priority class="absolute inset-0 -z-20 h-full w-full object-cover" />
    <div class="absolute inset-0 -z-10 bg-linear-to-br from-forest/95 via-forest-light/90 to-sage/85"></div>

    <div class="mx-auto w-full max-w-6xl px-6">
        <div class="grid items-center gap-10 lg:grid-cols-[1.1fr_0.9fr] lg:gap-16">
            <div class="text-cream">
                <p class="mb-6 inline-flex animate-fade-in-up items-center gap-2 border border-mint/30 bg-mint/15 px-4 py-2 text-sm font-medium text-mint-light">
                    <x-svg-icon name="star" class="h-4 w-4" />
                    {{ config('shop.brand.eyebrow') }}
                </p>

                <h1 class="mb-6 max-w-3xl font-display text-5xl leading-[0.98] font-semibold tracking-tight sm:text-6xl lg:text-7xl">
                    Raised here.<br>
                    <em class="text-mint-light not-italic">Delivered by us.</em>
                </h1>

                <p class="mb-8 max-w-2xl animate-fade-in-up text-lg leading-relaxed text-cream/85 [animation-delay:100ms] sm:text-xl">
                    {{ config('shop.brand.hero_description') }}
                </p>

                <ul class="flex animate-fade-in-up flex-wrap gap-x-6 gap-y-3 text-sm font-medium text-cream/90 [animation-delay:200ms] sm:text-base">
                    @foreach (['Pasture-raised', 'Murray Grey', 'Pickup or personal delivery'] as $point)
                        <li class="flex items-center gap-2">
                            <x-svg-icon name="check" class="text-mint" />
                            {{ $point }}
                        </li>
                    @endforeach
                </ul>
            </div>

            <aside class="border border-cream/20 bg-cream p-6 shadow-2xl shadow-black/20 sm:p-8" aria-labelledby="hero-offer-title">
                <p class="eyebrow mb-2 text-sage">Current availability</p>
                <h2 id="hero-offer-title" class="mb-2 font-display text-3xl font-semibold text-forest sm:text-4xl">
                    @if ($catalogue->startingBoxPrice() !== null)
                        Beef boxes from {{ Money::format($catalogue->startingBoxPrice()) }}
                    @else
                        Farm-direct beef boxes
                    @endif
                </h2>
                @php($state = $liveDrop['drop']['state'] ?? 'none')
                @php($announcedLabel = $liveDrop['announced']['label'] ?? null)
                @php($showAnnounced = $announcedLabel !== null && in_array($state, ['none', 'closed'], true))
                <div class="mb-6 text-gray-600" aria-live="polite">
                    <p data-drop-show="live" @if ($state !== 'live') hidden @endif>Orders are open now. Stock updates as people order.</p>
                    <p data-drop-show="sold_out" @if ($state !== 'sold_out') hidden @endif>
                        This drop’s boxes have sold out.
                        @if ($announcedLabel !== null)
                            <span data-drop-announced>The next drop is {{ $announcedLabel }}.</span>
                        @endif
                        Join the wait list for the next one.
                    </p>
                    <p data-drop-show="scheduled" @if ($state !== 'scheduled') hidden @endif>The next drop opens in <span data-drop-countdown></span>.</p>
                    @if ($announcedLabel !== null)
                        <p data-drop-show="announced" @if (! $showAnnounced) hidden @endif>The next drop is {{ $announcedLabel }}. Stock and prices are still to come, so join the wait list and we’ll text you when orders open.</p>
                    @endif
                    <p data-drop-show="closed none" @if (in_array($state, ['live', 'sold_out', 'scheduled'], true) || $showAnnounced) hidden @endif>Check the current drop, or join the next one if boxes have sold out.</p>
                </div>

                <div class="mb-6 divide-y divide-forest/10 border-y border-forest/10">
                    @foreach ($catalogue->boxes() as $box)
                        <div class="flex items-center justify-between gap-4 py-4">
                            <div>
                                <p class="font-semibold text-forest">{{ $box->name }}</p>
                                <p class="text-sm text-gray-600">{{ $box->box?->bestFor }}</p>
                            </div>
                            @if ($box->price !== null)
                                <div class="text-right">
                                    <p class="font-bold text-forest">{{ Money::format($box->price) }}</p>
                                    <p class="text-xs text-sage">{{ Money::perKg((int) $box->box?->perKgPrice) }}</p>
                                    @isset($liveDrop['items'][$box->slug])
                                        <p class="text-xs font-semibold text-forest" data-drop-stock="{{ $box->slug }}">{{ \App\Stock\StockLabel::text($liveDrop['items'][$box->slug]['available']) }}</p>
                                    @endisset
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="grid gap-3">
                    <x-button :href="route('order')">View current availability</x-button>
                    <x-button href="#about" variant="outline">Meet Daniel &amp; Tahlia</x-button>
                </div>
            </aside>
        </div>
    </div>
</section>
