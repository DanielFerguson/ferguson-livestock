{{-- The order form while a drop is open or coming up; the wait list between drops. --}}
<x-layouts.app
    title="Shop the Current Beef Drop | Ferguson Livestock"
    description="Order Ferguson Livestock Murray Grey beef boxes and individual cuts when a drop is open."
    robots="noindex, follow"
    :scripts="$drop !== null ? ['resources/js/order-form.js'] : []"
>
    @if ($drop !== null)
        <section class="bg-cream-dark py-12 lg:py-16">
            <div class="mx-auto max-w-4xl px-6">
                <x-section-heading eyebrow="Ordering" :level="1" size="page" class="mb-8">
                    {{ $drop->name }}
                </x-section-heading>

                <livewire:order-form :drop="$drop" />
            </div>
        </section>
    @else
        <section class="bg-forest py-16 text-cream lg:py-24">
            <div class="mx-auto max-w-4xl px-6 text-center">
                @if ($announcedLabel !== null)
                    <x-section-heading eyebrow="Ordering" :level="1" size="page" tone="dark" align="center" intro="We’re still finalising what’s in it. Join the wait list below and we’ll text you as soon as orders open.">
                        The next drop is {{ $announcedLabel }}.
                    </x-section-heading>
                @else
                    <x-section-heading eyebrow="Ordering" :level="1" size="page" tone="dark" align="center" intro="Ordering moves to this page when the next drop opens. Join the wait list below and we’ll text you as soon as it does.">
                        The next drop is being prepared.
                    </x-section-heading>
                @endif
            </div>
        </section>
    @endif

    @if ($drop === null)
        <x-waitlist-form />
    @endif
</x-layouts.app>
