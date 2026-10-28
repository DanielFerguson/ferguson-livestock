@props(['title', 'description', 'eyebrow', 'heading', 'message'])

<x-layouts.app :title="$title" :description="$description" robots="noindex, follow">
    <div class="bg-cream px-6 py-24 text-center lg:py-36">
        <p class="eyebrow mb-4 text-sage">{{ $eyebrow }}</p>
        <h1 class="mx-auto max-w-2xl font-display text-5xl font-semibold text-forest md:text-7xl">{{ $heading }}</h1>
        <p class="mx-auto mt-6 max-w-xl text-lg leading-8 text-gray-700">{{ $message }}</p>
        <div class="mt-9 flex flex-col justify-center gap-3 sm:flex-row">
            <x-button :href="route('home')" class="px-7">Back home</x-button>
            <x-button :href="route('order')" variant="outline" class="px-7">Shop the current drop</x-button>
            <x-button :href="route('contact')" variant="outline" class="px-7">Contact us</x-button>
        </div>
    </div>
</x-layouts.app>
