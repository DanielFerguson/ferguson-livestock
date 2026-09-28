@php
    $steps = [
        ['Check the current drop', 'See which boxes and individual cuts are available now.'],
        ['Choose what suits you', 'Pick a 5kg or 10kg box, then add any available extra cuts.'],
        ['Professionally prepared', 'A licensed local facility prepares and vacuum-seals each cut before collection or delivery.'],
        ['Delivery or pickup', 'Choose personal delivery across the '.config('shop.delivery.area_name').' or free farm pickup by arrangement in '.config('shop.location.locality').'.'],
    ];
@endphp

<section class="bg-cream py-16 lg:py-24">
    <div class="mx-auto max-w-6xl px-6">
        <x-section-heading
            eyebrow="The process"
            size="md"
            align="center"
            intro="A simple process that puts quality first at every step."
            class="mb-12 lg:mb-16"
        >
            From our paddock to your door
        </x-section-heading>

        <ol class="grid gap-8 md:grid-cols-2 lg:grid-cols-4">
            @foreach ($steps as [$title, $description])
                <li class="text-center">
                    <span class="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-linear-to-br from-forest to-sage font-display text-2xl font-semibold text-mint-light shadow-lg shadow-forest/20" aria-hidden="true">
                        {{ $loop->iteration }}
                    </span>
                    <h3 class="mb-3 font-display text-xl font-semibold text-forest">{{ $title }}</h3>
                    <p class="leading-relaxed text-gray-600">{{ $description }}</p>
                </li>
            @endforeach
        </ol>

        <div class="mt-12 text-center lg:mt-16">
            <x-button :href="route('order')" class="px-8">
                View current availability
                <x-svg-icon name="arrow-right" />
            </x-button>
        </div>
    </div>
</section>
