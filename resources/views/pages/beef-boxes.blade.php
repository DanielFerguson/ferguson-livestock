@use('App\Support\Money')
@use('App\Support\StructuredData')

@php
    $title = 'Farm-Direct Murray Grey Beef Boxes | Ferguson Livestock';
    $description = 'Compare Ferguson Livestock’s 5kg and 10kg Murray Grey beef boxes, current prices, typical contents, freezer guidance, delivery and pickup options.';
@endphp

<x-layouts.app :title="$title" :description="$description" :structured-data="StructuredData::beefBoxes($title, $description, $catalogue)">
    <section class="bg-forest py-16 text-cream lg:py-24">
        <div class="mx-auto grid max-w-6xl items-center gap-12 px-6 lg:grid-cols-2">
            <div>
                <x-section-heading
                    eyebrow="Small-batch Murray Grey beef"
                    :level="1"
                    size="page"
                    tone="dark"
                    intro="A practical mix of steaks, roasts, slow-cook cuts, sausages and mince—professionally prepared, vacuum-sealed and ready for your freezer."
                >
                    A box for the way your household cooks.
                </x-section-heading>
                <div class="mt-8 flex flex-wrap gap-3">
                    <x-button :href="route('order')" variant="mint">View current availability</x-button>
                    <x-button :href="route('delivery')" variant="outline-light">Delivery &amp; pickup</x-button>
                </div>
            </div>

            <x-picture
                name="beef-box"
                alt="Ferguson Livestock Murray Grey beef packed in branded freezer boxes"
                sizes="(max-width: 1024px) 100vw, 50vw"
                priority
                class="aspect-square w-full object-cover"
            />
        </div>
    </section>

    <section class="bg-cream py-16 lg:py-24">
        <div class="mx-auto max-w-6xl px-6">
            <x-section-heading eyebrow="Choose your box" size="md" class="mb-10 max-w-2xl">
                Straightforward sizes. Honest value.
            </x-section-heading>

            <div class="grid gap-8 lg:grid-cols-2">
                @foreach ($catalogue->boxes() as $box)
                    <article class="border border-forest/10 bg-white p-6 sm:p-8">
                        <div class="mb-6">
                            <h3 class="font-display text-3xl font-semibold text-forest">{{ $box->name }}</h3>
                            <p class="mt-1 text-gray-600">{{ $box->box?->bestFor }}</p>
                        </div>
                        <div class="mb-6 flex items-end gap-3 border-b border-forest/10 pb-6">
                            <span class="font-display text-5xl font-semibold text-forest">{{ Money::format($box->price) }}</span>
                            <span class="pb-1 text-sm font-semibold text-sage">{{ Money::perKg($box->box->perKgPrice ?? 0) }}</span>
                        </div>
                        <ul class="space-y-3 text-base leading-relaxed text-gray-700">
                            @foreach ($box->box->contents ?? [] as $item)
                                <li class="flex gap-3">
                                    <x-icon name="check" class="mt-0.5 text-sage" />
                                    <span>{{ $item }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-6 border-t border-forest/10 pt-5 text-sm text-gray-600">
                            {{ $box->box?->freezerGuidance }} Individual cuts vary naturally from animal to animal.
                        </p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>
</x-layouts.app>
