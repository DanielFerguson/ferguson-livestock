@php
    use App\Stock\StockLabel;
    use App\Support\Money;
@endphp

<fieldset class="border border-forest/10 bg-white p-6 inert:opacity-50">
    <legend class="float-left mb-4 w-full font-display text-2xl font-semibold text-forest">1. Choose a box</legend>

    <div class="clear-both grid gap-3">
        @foreach ($boxes as $item)
            @php($weight = (int) ($item->product->box_details['weight_kg'] ?? 0))
            <label class="flex cursor-pointer items-center gap-4 border border-forest/15 p-4 has-checked:border-sage has-checked:bg-mint/10 has-disabled:cursor-not-allowed has-disabled:opacity-60">
                <input type="radio" name="box" value="{{ $item->id }}" wire:model="box" class="h-5 w-5 accent-sage"
                    @disabled($available($item) < 1) x-bind:disabled="items[{{ $item->id }}].available < 1">
                <span class="flex-1">
                    <span class="block font-semibold text-forest">{{ $item->product->name }}</span>
                    <span class="block text-sm text-gray-600">{{ $item->product->box_details['best_for'] ?? '' }}</span>
                </span>
                <span class="text-right">
                    <span class="block font-bold text-forest">{{ Money::format($item->price) }}</span>
                    @if ($weight > 0)
                        <span class="block text-xs text-sage">{{ Money::perKg(intdiv($item->price, $weight)) }}</span>
                    @endif
                    <span class="block text-sm text-gray-700" data-drop-stock="{{ $item->product->slug }}">{{ StockLabel::text($available($item)) }}</span>
                </span>
            </label>
        @endforeach

        <label class="flex cursor-pointer items-center gap-4 border border-forest/15 p-4 has-checked:border-sage has-checked:bg-mint/10">
            <input type="radio" name="box" value="none" wire:model="box" class="h-5 w-5 accent-sage">
            <span class="font-semibold text-forest">No box, just extras</span>
        </label>
    </div>
</fieldset>
