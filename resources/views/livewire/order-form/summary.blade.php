@php
    use App\Support\Money;
@endphp

<section class="border border-forest/10 bg-white p-6 inert:opacity-50" aria-labelledby="order-summary" x-bind:inert="! ready()">
    <h2 id="order-summary" class="font-display text-2xl font-semibold text-forest">4. Review and pay</h2>

    <ul class="mt-4 space-y-1">
        <template x-for="line in lines()" :key="line.name">
            <li class="flex justify-between gap-4">
                <span x-text="line.label"></span>
                <span x-text="money(line.total)"></span>
            </li>
        </template>
        @if ($deliveryFee !== null)
            <li class="flex justify-between gap-4" x-show="$wire.deliveryMethod === 'delivery'">
                <span>Delivery</span>
                <span>{{ Money::format($deliveryFee->price) }}</span>
            </li>
        @endif
        <li class="flex justify-between gap-4 border-t border-forest/10 pt-2 font-semibold text-forest">
            <span>Total</span>
            <span x-text="money(total({{ $deliveryFee->price ?? 0 }}))"></span>
        </li>
    </ul>

    <div class="mt-6">
        {{-- wire:ignore keeps Livewire from wiping the widget when the form re-renders. --}}
        <x-turnstile id="order-turnstile" class="mb-4" wire:ignore />
        <x-button class="w-full sm:w-auto" x-bind:disabled="! open" wire:loading.attr="disabled">
            <span x-show="open">Continue to payment</span>
            <span x-show="! open" @if ($status->isOpen()) hidden @endif>Orders open in <span data-drop-countdown></span></span>
        </x-button>
        <p wire:loading wire:target="checkout" role="status" class="mt-3 text-sm text-gray-600">Holding your order and opening secure payment…</p>
        <p class="mt-3 text-sm text-gray-600">You’ll pay on Stripe’s secure page. We hold your items for 30 minutes while you do.</p>
    </div>
</section>
