@props(['catalogue'])

@use('App\Support\Money')

<section class="bg-forest py-16 text-cream lg:py-24">
    <div class="mx-auto grid max-w-6xl items-center gap-12 px-6 lg:grid-cols-2 lg:gap-20">
        <div>
            <x-section-heading
                eyebrow="What’s in the box"
                tone="dark"
                intro="Each box balances primary steaks with roast, slow-cook cuts, sausages and mince. The exact cuts vary naturally, but the total weight and value stay clear."
                class="mb-8"
            >
                The cuts you look forward to—and the ones that keep weeknights moving.
            </x-section-heading>

            <div class="grid gap-px bg-cream/15 sm:grid-cols-2">
                @foreach ($catalogue->boxes() as $box)
                    <div class="bg-forest-light p-6">
                        <p class="font-display text-3xl font-semibold">{{ $box->name }}</p>
                        <p class="mt-1 text-sm text-cream/75">{{ $box->box?->bestFor }}</p>
                        @if ($box->price !== null)
                            <p class="mt-5 text-2xl font-bold text-mint-light">
                                {{ Money::format($box->price) }}
                                <span class="text-sm font-medium text-cream/75">· {{ Money::perKg((int) $box->box?->perKgPrice) }}</span>
                            </p>
                        @endif
                    </div>
                @endforeach
            </div>

            <x-button :href="route('beef-boxes')" variant="mint" class="mt-8">Compare the boxes</x-button>
        </div>

        <x-picture
            name="beef-box"
            alt="Ferguson Livestock Murray Grey beef packed and ready for the freezer"
            sizes="(max-width: 1024px) 100vw, 50vw"
            class="aspect-square w-full object-cover"
        />
    </div>
</section>
