<x-layouts.app
    title="Page Not Found | Ferguson Livestock"
    description="The page you were looking for could not be found."
    robots="noindex, follow"
>
    <div class="bg-cream px-6 py-24 text-center lg:py-36">
        <p class="eyebrow mb-4 text-sage">404 · Wrong paddock</p>
        <h1 class="mx-auto max-w-2xl font-display text-5xl font-semibold text-forest md:text-7xl">This page has wandered off.</h1>
        <p class="mx-auto mt-6 max-w-xl text-lg leading-8 text-gray-700">
            Head back home, browse the current beef drop, or get in touch with Daniel and Tahlia if you need a hand.
        </p>
        <div class="mt-9 flex flex-col justify-center gap-3 sm:flex-row">
            <x-button :href="route('home')" class="px-7">Back home</x-button>
            <x-button :href="route('order')" variant="outline" class="px-7">Shop the current drop</x-button>
            <x-button :href="route('contact')" variant="outline" class="px-7">Contact us</x-button>
        </div>
    </div>
</x-layouts.app>
