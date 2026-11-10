@php
    use App\Stock\StockLabel;
    use App\Support\Money;
@endphp

@if ($extraItems->isNotEmpty())
    <fieldset class="border border-forest/10 bg-white p-6 inert:opacity-50">
        <legend class="float-left mb-4 w-full font-display text-2xl font-semibold text-forest">2. Add extras (optional)</legend>

        <ul class="clear-both divide-y divide-forest/10">
            @foreach ($extraItems as $item)
                {{-- A sold-out extra is shaded and struck through; the live stock feed keeps that up to date. --}}
                <li class="group -mx-3 flex flex-wrap items-center gap-4 px-3 py-4 data-sold-out:bg-cream"
                    @if ($available($item) < 1) data-sold-out @endif x-bind:data-sold-out="items[{{ $item->id }}].available < 1">
                    <span class="min-w-40 flex-1">
                        <span class="block font-semibold text-forest group-data-sold-out:text-gray-500 group-data-sold-out:line-through">{{ $item->product->name }}</span>
                        <span class="block text-sm text-gray-600">
                            <span class="group-data-sold-out:line-through">{{ Money::format($item->price) }}</span> ·
                            <span class="group-data-sold-out:font-semibold group-data-sold-out:text-forest" data-drop-stock="{{ $item->product->slug }}">{{ StockLabel::text($available($item)) }}</span>
                        </span>
                    </span>

                    <span class="flex items-center gap-2">
                        <button type="button" class="h-11 w-11 border border-forest/25 text-xl text-forest disabled:opacity-40"
                            x-on:click="remove({{ $item->id }})" x-bind:disabled="quantity({{ $item->id }}) < 1"
                            aria-label="One fewer {{ $item->product->name }}">−</button>
                        <output class="w-8 text-center font-semibold tabular-nums group-data-sold-out:text-gray-400" aria-live="polite"
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
