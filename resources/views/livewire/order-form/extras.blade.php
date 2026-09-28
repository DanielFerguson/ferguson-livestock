@php
    use App\Stock\StockLabel;
    use App\Support\Money;
@endphp

@if ($extraItems->isNotEmpty())
    <fieldset class="border border-forest/10 bg-white p-6 inert:opacity-50">
        <legend class="float-left mb-4 w-full font-display text-2xl font-semibold text-forest">2. Add extras (optional)</legend>

        <ul class="clear-both divide-y divide-forest/10">
            @foreach ($extraItems as $item)
                <li class="flex flex-wrap items-center gap-4 py-4">
                    <span class="min-w-40 flex-1">
                        <span class="block font-semibold text-forest">{{ $item->product->name }}</span>
                        <span class="block text-sm text-gray-600">
                            {{ Money::format($item->price) }} ·
                            <span data-drop-stock="{{ $item->product->slug }}">{{ StockLabel::text($available($item)) }}</span>
                        </span>
                    </span>

                    <span class="flex items-center gap-2">
                        <button type="button" class="h-11 w-11 border border-forest/25 text-xl text-forest disabled:opacity-40"
                            x-on:click="remove({{ $item->id }})" x-bind:disabled="quantity({{ $item->id }}) < 1"
                            aria-label="One fewer {{ $item->product->name }}">−</button>
                        <output class="w-8 text-center font-semibold tabular-nums" aria-live="polite"
                            x-text="quantity({{ $item->id }})">{{ $extras[$item->id] ?? 0 }}</output>
                        <button type="button" class="h-11 w-11 border border-forest/25 text-xl text-forest disabled:opacity-40"
                            x-on:click="add({{ $item->id }})" x-bind:disabled="quantity({{ $item->id }}) >= limit({{ $item->id }})"
                            aria-label="One more {{ $item->product->name }}">+</button>
                    </span>
                </li>
            @endforeach
        </ul>
    </fieldset>
@endif
