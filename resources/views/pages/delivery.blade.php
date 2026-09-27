@use('App\Support\Money')
@use('App\Support\StructuredData')

@php
    $area = config()->string('shop.delivery.area_name');
    $title = 'Beef Delivery and Farm Pickup Ballarat | Ferguson Livestock';
    $description = "Personal Ferguson Livestock delivery across the {$area}, plus free farm pickup by arrangement in Snake Valley, and what to do if something isn’t right.";
    $deliveryFee = Money::format($catalogue->deliveryFee());
@endphp

<x-layouts.app :title="$title" :description="$description" :structured-data="StructuredData::webPage('/delivery', $title, $description)">
    <section class="bg-forest py-16 text-cream lg:py-24">
        <div class="mx-auto max-w-4xl px-6 text-center">
            <x-section-heading
                eyebrow="From Snake Valley to your door"
                :level="1"
                size="page"
                tone="dark"
                align="center"
                intro="We personally deliver orders across the {{ $area }}, or you can collect from the farm by arrangement."
            >
                Delivery with a farmer at the other end.
            </x-section-heading>
        </div>
    </section>

    <section class="bg-cream py-16 lg:py-24">
        <div class="mx-auto grid max-w-5xl gap-8 px-6 md:grid-cols-2">
            <article class="border border-forest/10 bg-white p-8">
                <p class="eyebrow mb-3 text-sage">Personal delivery</p>
                <h2 class="font-display text-4xl font-semibold text-forest">{{ $deliveryFee }} flat fee</h2>
                <p class="mt-4 leading-relaxed text-gray-700">We deliver across the {{ $area }} and confirm the delivery window with you directly. If nobody will be home, arrange a safe, chilled spot with us beforehand. If you’re close to Ballarat but unsure whether your postcode is covered, contact us before ordering.</p>
            </article>
            <article class="border border-forest/10 bg-white p-8">
                <p class="eyebrow mb-3 text-sage">Farm pickup</p>
                <h2 class="font-display text-4xl font-semibold text-forest">Free by arrangement</h2>
                <p class="mt-4 leading-relaxed text-gray-700">Collect from Ferguson Livestock in {{ config('shop.location.locality') }}. We confirm the pickup window and directions after your order. Please don’t arrive without a confirmed time—the farm is a working property, not a shopfront.</p>
            </article>
        </div>
        <div class="mx-auto mt-10 max-w-5xl px-6 text-center">
            <x-button :href="route('order')" class="px-7">View current availability</x-button>
        </div>
    </section>

    <section id="refunds" class="border-t border-forest/10 bg-cream py-16 lg:py-24">
        <article class="legal-content mx-auto max-w-3xl px-6 text-gray-700">
            <h2>If something isn’t right</h2>
            <p>We want every Ferguson Livestock order to arrive in the condition we intended. Because our products are perishable, please check your order as soon as it arrives.</p>

            <h3 class="mt-8 mb-3 font-display text-2xl font-semibold text-forest">Check your order promptly</h3>
            <p>Refrigerate or freeze the beef as soon as possible. Contact us promptly if packaging is damaged, a vacuum seal has failed, the product is not appropriately chilled, or the order is materially different from what you purchased. Photographs help us assess packaging or delivery issues quickly.</p>

            <h3 class="mt-8 mb-3 font-display text-2xl font-semibold text-forest">Replacements and refunds</h3>
            <p>When a product or delivery issue is confirmed, we will work with you on an appropriate replacement, credit or refund. We assess change-of-mind requests individually because chilled and frozen food cannot always be safely returned or resold. Nothing on this page limits rights that apply under Australian consumer law.</p>

            <h3 class="mt-8 mb-3 font-display text-2xl font-semibold text-forest">Get in touch</h3>
            <p>Call or text <a href="tel:{{ config('shop.phone.international') }}">{{ config('shop.phone.display') }}</a>, or email <a href="mailto:{{ config('shop.email') }}">{{ config('shop.email') }}</a>.</p>
        </article>
    </section>
</x-layouts.app>
