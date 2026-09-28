@use('App\Support\StructuredData')

@php
    $title = 'Meet Daniel and Tahlia Ferguson | Ferguson Livestock';
    $description = 'Meet the young Snake Valley farmers behind Ferguson Livestock and learn why they are building their future around Murray Grey cattle and local customers.';
@endphp

<x-layouts.app :title="$title" :description="$description" :structured-data="StructuredData::webPage('/our-story', $title, $description, 'AboutPage')">
    <section class="bg-cream py-16 lg:py-24">
        <div class="mx-auto grid max-w-6xl items-center gap-12 px-6 lg:grid-cols-2">
            <div>
                <x-section-heading eyebrow="Our story" :level="1" size="page" class="mb-6">
                    Two young farmers building something of their own.
                </x-section-heading>
                <p class="text-xl leading-relaxed text-gray-700">We’re Daniel and Tahlia Ferguson. We raise Murray Grey cattle in Snake Valley and are building a farm-direct beef business around good cattle, honest value and knowing the people we serve.</p>
            </div>
            <x-picture
                name="family-landscape"
                alt="Daniel and Tahlia Ferguson with their Murray Grey cattle"
                sizes="(max-width: 1024px) 100vw, 50vw"
                priority
                class="aspect-4/3 w-full object-cover"
            />
        </div>
    </section>

    <section class="bg-cream-dark py-16 lg:py-24">
        <div class="mx-auto grid max-w-6xl gap-12 px-6 lg:grid-cols-[0.85fr_1.15fr] lg:items-center">
            <x-picture
                name="daniel-with-cattle"
                alt="Daniel with one of Ferguson Livestock’s Murray Grey cattle"
                sizes="(max-width: 1024px) 100vw, 40vw"
                class="aspect-4/5 w-full object-cover"
            />
            <div class="space-y-6 text-lg leading-relaxed text-gray-700">
                <h2 class="font-display text-4xl font-semibold text-forest sm:text-5xl">Why Murray Greys?</h2>
                <p>Murray Greys connect the things we care about: calm, capable cattle; a breed with Victorian roots; and the opportunity to build a herd we can be proud to put our name behind.</p>
                <p>Showing cattle has made the details visible—structure, temperament, preparation and the daily work that sits behind a result. Those same standards shape how we approach the beef side of Ferguson Livestock.</p>
                <p>When you order from us, you are dealing directly with the people who raised the cattle. We answer the questions, prepare each drop with our local processing partners and deliver orders around the {{ config('shop.delivery.area_name') }} ourselves.</p>
                <blockquote class="border-l-4 border-sage pl-6 font-display text-3xl leading-snug font-semibold text-forest">We’re still growing, and we’re proud to be doing it with the support of our neighbours.</blockquote>
            </div>
        </div>
    </section>
</x-layouts.app>
