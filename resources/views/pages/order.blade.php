{{-- Placeholder until the live order page replaces it. Only reachable on staging and previews. --}}
<x-layouts.app
    title="Shop the Current Beef Drop | Ferguson Livestock"
    description="Order Ferguson Livestock Murray Grey beef boxes and individual cuts when a drop is open."
    robots="noindex, follow"
>
    <section class="bg-forest py-16 text-cream lg:py-24">
        <div class="mx-auto max-w-4xl px-6 text-center">
            <x-section-heading eyebrow="Ordering" :level="1" size="page" tone="dark" align="center" intro="Ordering moves to this page when the next drop opens. Join the wait list below and we’ll text you as soon as it does.">
                The next drop is being prepared.
            </x-section-heading>
        </div>
    </section>

    <x-waitlist-form />
</x-layouts.app>
