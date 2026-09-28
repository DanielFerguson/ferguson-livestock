<section id="about" class="bg-cream py-16 lg:py-24">
    <div class="mx-auto grid max-w-6xl items-center gap-12 px-6 lg:grid-cols-[1.05fr_0.95fr] lg:gap-20">
        <div class="grid grid-cols-[1.2fr_0.8fr] items-end gap-4">
            <x-picture
                name="family-portrait"
                alt="Daniel and Tahlia Ferguson with their Murray Grey cattle"
                sizes="(max-width: 1024px) 65vw, 33vw"
                class="aspect-4/5 w-full object-cover"
            />
            <x-picture
                name="daniel-with-cattle-tall"
                alt="Daniel Ferguson with one of the Ferguson Livestock Murray Grey cattle"
                sizes="(max-width: 1024px) 35vw, 22vw"
                class="mb-8 aspect-3/4 w-full object-cover"
            />
        </div>

        <div>
            <x-section-heading eyebrow="Meet your farmers" class="mb-6">
                Young farmers. Good cattle. A future worth building.
            </x-section-heading>
            <div class="space-y-5 text-lg leading-relaxed text-gray-700">
                <p>We raise Murray Grey cattle on our property in Snake Valley, {{ config('shop.location.proximity') }}.</p>
                <p>Ferguson Livestock is our chance to build something of our own in farming—one herd, one beef drop and one local customer relationship at a time.</p>
                <p>We know the cattle behind every drop, work directly with our local processing partners, and deliver orders ourselves. If you have a question, you talk to us.</p>
            </div>
            <a href="{{ route('our-story') }}" class="mt-8 inline-block border-b-2 border-sage pb-1 font-semibold text-forest no-underline transition-colors hover:text-sage">
                Read Daniel &amp; Tahlia’s story <span aria-hidden="true">→</span>
            </a>
        </div>
    </div>
</section>
