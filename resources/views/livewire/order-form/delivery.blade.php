@php
    use App\Support\Money;
    use Carbon\CarbonImmutable;
@endphp

<fieldset class="border border-forest/10 bg-white p-6 inert:opacity-50" x-bind:inert="! hasItems()">
    <legend class="float-left mb-4 w-full font-display text-2xl font-semibold text-forest">3. Delivery or pickup</legend>

    <div class="clear-both grid gap-3 sm:grid-cols-2">
        @if ($deliveryFee !== null)
            <label class="flex cursor-pointer items-start gap-3 border border-forest/15 p-4 has-checked:border-sage has-checked:bg-mint/10">
                <input type="radio" name="deliveryMethod" value="delivery" wire:model="deliveryMethod" class="mt-1 h-5 w-5 accent-sage">
                <span>
                    <span class="block font-semibold text-forest">Delivery, {{ Money::format($deliveryFee->price) }}</span>
                    <span class="block text-sm text-gray-600">Afternoon or evening, around the Ballarat region.</span>
                </span>
            </label>
        @endif
        <label class="flex cursor-pointer items-start gap-3 border border-forest/15 p-4 has-checked:border-sage has-checked:bg-mint/10">
            <input type="radio" name="deliveryMethod" value="pickup" wire:model="deliveryMethod" class="mt-1 h-5 w-5 accent-sage">
            <span>
                <span class="block font-semibold text-forest">Farm pickup, free</span>
                <span class="block text-sm text-gray-600">From our farm in {{ config('shop.location.locality') }}, at a time we arrange.</span>
            </span>
        </label>
    </div>

    @if ($deliveryFee !== null && $drop->delivery_days !== [])
        <fieldset class="mt-6" x-show="$wire.deliveryMethod === 'delivery'">
            <legend class="font-semibold text-forest">Delivery day</legend>
            <div class="mt-2 flex flex-wrap gap-3">
                @foreach ($drop->delivery_days as $day)
                    <label class="flex cursor-pointer items-center gap-2 border border-forest/15 px-4 py-3 has-checked:border-sage has-checked:bg-mint/10">
                        <input type="radio" name="deliveryDay" value="{{ $day }}" wire:model="deliveryDay" class="h-5 w-5 accent-sage">
                        {{ CarbonImmutable::parse($day)->format('l j F') }}
                    </label>
                @endforeach
            </div>
        </fieldset>
    @endif
</fieldset>
