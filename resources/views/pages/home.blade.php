@use('App\Support\StructuredData')

@php
    $title = 'Murray Grey Beef Boxes Ballarat | Ferguson Livestock';
    $description = config()->string('shop.brand.short_description');
@endphp

<x-layouts.app :title="$title" :description="$description" :structured-data="StructuredData::home($title, $description)">
    <x-slot:banner>
        <x-announcement-bar />
    </x-slot:banner>

    <x-home.hero :catalogue="$catalogue" />
    <x-home.brand-proof />
    <x-home.meet-the-fergusons />
    <x-home.whats-in-the-box :catalogue="$catalogue" />
    <x-home.how-it-works />
    <x-home.why-murray-grey />
    {{-- FAQPage markup lives only on /faq, where Google expects it. --}}
    <x-faq-list :faqs="$faqs" />
    <x-waitlist-form />
</x-layouts.app>
