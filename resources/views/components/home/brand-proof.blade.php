<section class="border-y border-forest/10 bg-cream-dark" aria-label="About Ferguson Livestock">
    <dl class="mx-auto grid max-w-6xl grid-cols-2 px-6 md:grid-cols-4">
        @foreach ([
            'Your farmers' => 'Daniel & Tahlia',
            'Raised nearby' => 'Snake Valley, VIC',
            'Our cattle' => 'Murray Grey',
            'Your order' => 'Delivered by us',
        ] as $label => $value)
            <div class="border-forest/10 px-3 py-6 text-center even:border-l md:border-l md:first:border-l-0">
                <dt class="eyebrow text-sage">{{ $label }}</dt>
                <dd class="mt-1 font-display text-xl font-semibold text-forest sm:text-2xl">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
</section>
