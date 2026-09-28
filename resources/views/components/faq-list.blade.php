{{-- Questions open and close without JavaScript. `name` lets modern browsers keep one open at a time. --}}
@props(['faqs', 'headingLevel' => 2])

<section {{ $attributes->merge(['class' => 'bg-cream py-16 lg:py-24']) }}>
    <div class="mx-auto max-w-6xl px-6">
        <x-section-heading
            eyebrow="Good to know"
            :level="$headingLevel"
            size="md"
            align="center"
            intro="Straight answers about boxes, delivery and ordering."
            class="mb-12 lg:mb-16"
        >
            Frequently asked questions
        </x-section-heading>

        <div class="mx-auto max-w-3xl space-y-4">
            @foreach ($faqs as $faq)
                <details name="faq" class="group border border-forest/10 bg-white">
                    <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-4 px-6 py-5 text-lg font-semibold text-forest transition-colors hover:text-sage [&::-webkit-details-marker]:hidden">
                        {{ $faq['question'] }}
                        <x-svg-icon name="plus" class="h-6 w-6 text-sage transition-transform group-open:rotate-45" />
                    </summary>
                    <p class="px-6 pb-6 leading-relaxed text-gray-700">{{ $faq['answer'] }}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>
