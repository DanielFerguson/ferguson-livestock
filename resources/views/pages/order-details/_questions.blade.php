{{-- Ways to reach the farm. Expects $textUsUrl. --}}
<section aria-labelledby="questions-heading" class="border border-forest/5 bg-white p-8 shadow-xl">
    <h2 id="questions-heading" class="mb-3 font-display text-2xl font-semibold text-forest">Questions?</h2>
    <p class="mb-5">Text us on {{ config('shop.phone.display') }} or call {{ config('shop.call.display') }}. It’s just the two of us, so we’ll get back to you as soon as we can.</p>

    <div class="flex flex-wrap gap-3">
        <x-button :href="$textUsUrl">
            <x-svg-icon name="message" />
            Text us
        </x-button>
        <x-button :href="'tel:'.config('shop.call.international')" variant="outline">Call {{ config('shop.call.display') }}</x-button>
    </div>
</section>
