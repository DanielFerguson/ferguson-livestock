@use('App\Support\StructuredData')

@php
    $title = 'Contact Daniel and Tahlia | Ferguson Livestock';
    $description = 'Talk directly with Daniel and Tahlia Ferguson about Murray Grey beef boxes, Ballarat-region delivery or farm pickup in Snake Valley.';
@endphp

<x-layouts.app :title="$title" :description="$description" :structured-data="StructuredData::webPage('/contact', $title, $description, 'ContactPage')">
    <div class="bg-cream py-16 lg:py-24">
        <div class="mx-auto grid max-w-5xl gap-12 px-6 lg:grid-cols-2">
            <div>
                <x-section-heading eyebrow="Talk to the farmers" :level="1" size="page" class="mb-6">
                    Questions? You’ll reach Daniel or Tahlia.
                </x-section-heading>
                <p class="text-xl leading-relaxed text-gray-700">Ask about the next drop, box contents, delivery, pickup or the Murray Grey cattle behind Ferguson Livestock.</p>
            </div>
            <div class="border border-forest/10 bg-white p-8 sm:p-10">
                <dl class="space-y-7">
                    <div>
                        <dt class="eyebrow text-sage">Phone or text</dt>
                        <dd class="mt-2"><a class="font-display text-3xl font-semibold text-forest no-underline hover:text-sage" href="tel:{{ config('shop.phone.international') }}">{{ config('shop.phone.display') }}</a></dd>
                    </div>
                    <div>
                        <dt class="eyebrow text-sage">Email</dt>
                        <dd class="mt-2"><a class="text-lg break-words text-forest no-underline hover:text-sage" href="mailto:{{ config('shop.email') }}">{{ config('shop.email') }}</a></dd>
                    </div>
                    <div>
                        <dt class="eyebrow text-sage">Farm</dt>
                        <dd class="mt-2 text-lg text-gray-700">{{ config('shop.location.locality') }}, {{ config('shop.location.region') }}<br>Visits and pickup by arrangement.</dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</x-layouts.app>
