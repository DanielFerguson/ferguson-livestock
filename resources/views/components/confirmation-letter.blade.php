{{-- The signed note shown after joining the wait list (and, later, after ordering). Needs the 'caveat' font. --}}
@props(['heading', 'subheading'])

<div class="mx-auto w-full max-w-2xl">
    <div class="mb-8 text-center">
        <span class="mb-6 inline-flex h-20 w-20 items-center justify-center rounded-full bg-mint/20">
            <x-icon name="check-circle" class="h-10 w-10 text-sage" />
        </span>
        <h1 class="mb-2 font-display text-4xl font-semibold text-forest md:text-5xl">{{ $heading }}</h1>
        <p class="text-lg text-gray-600">{{ $subheading }}</p>
    </div>

    <div class="mb-8 border border-forest/5 bg-white p-8 shadow-xl md:p-12">
        <div class="space-y-6 text-lg leading-relaxed text-gray-700">
            {{ $slot }}
        </div>

        <div class="mt-8 border-t border-gray-100 pt-8">
            <p class="mb-2 text-gray-600">With gratitude,</p>
            <p class="mb-1 font-handwriting text-4xl text-forest">Daniel &amp; Tahlia</p>
            <p class="text-sm text-gray-600">{{ config('shop.name') }}, {{ config('shop.location.locality') }}</p>
        </div>
    </div>

    {{ $actions ?? '' }}
</div>
