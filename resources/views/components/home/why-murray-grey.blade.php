{{-- A short teaser; the full reasoning lives on /our-story. --}}
<section class="bg-cream-dark py-16 lg:py-24">
    <div class="mx-auto grid max-w-6xl items-center gap-12 px-6 lg:grid-cols-2 lg:gap-20">
        <x-picture
            name="murray-grey"
            alt="One of the Ferguson Livestock Murray Grey cattle"
            sizes="(max-width: 1024px) 100vw, 50vw"
            class="aspect-square w-full object-cover"
        />

        <div>
            <x-section-heading eyebrow="Why Murray Grey" class="mb-6">
                The breed at the centre of our farm.
            </x-section-heading>
            <p class="text-lg leading-relaxed text-gray-700">
                Murray Greys are part of Victoria’s cattle story, and they are the breed we have chosen to build Ferguson Livestock around.
            </p>
            <a href="{{ route('our-story') }}" class="mt-8 inline-block border-b-2 border-sage pb-1 font-semibold text-forest no-underline transition-colors hover:text-sage">
                Why we chose the breed <span aria-hidden="true">→</span>
            </a>
        </div>
    </div>
</section>
