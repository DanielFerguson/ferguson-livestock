@use('App\Support\StructuredData')

@php
    $title = 'Beef Box Questions and Answers | Ferguson Livestock';
    $description = 'Straight answers about Ferguson Livestock beef-box contents, prices, individual cuts, delivery, pickup, farming and sold-out drops.';
@endphp

<x-layouts.app :title="$title" :description="$description" :structured-data="[StructuredData::webPage('/faq', $title, $description), StructuredData::faq($faqs)]">
    <x-faq-list :faqs="$faqs" :heading-level="1" />
</x-layouts.app>
