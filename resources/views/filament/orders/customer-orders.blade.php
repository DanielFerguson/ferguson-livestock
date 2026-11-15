{{--
    What to pack, from App\Filament\Resources\Orders\Schemas\OrderInfolist. When the customer has more than one order
    on the drop, each is listed with its status, the opened one first, so nothing they ordered is left behind.
--}}
@php
    /** @var array<int, array{reference: string, status: \App\Enums\OrderStatus, note: string|null, opened: bool, items: array<int, array{line: string, free: bool}>}> $orders */
    $orders = $getState();
@endphp

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    <div class="fl-customer-orders">
        @foreach ($orders as $order)
            <div class="fl-customer-order">
                @if (count($orders) > 1)
                    <p class="fl-customer-order-heading">
                        <span>{{ $order['reference'] }}</span>
                        <x-filament::badge :color="$order['status']->getColor()" size="sm">{{ $order['status']->getLabel() }}</x-filament::badge>
                        @if ($order['opened'])
                            <span class="fl-customer-order-note">This one</span>
                        @endif
                        @if ($order['note'] !== null)
                            <span class="fl-customer-order-note">{{ $order['note'] }}</span>
                        @endif
                    </p>
                @endif
                <ul class="fl-customer-order-items">
                    @foreach ($order['items'] as $item)
                        <li>
                            <span>{{ $item['line'] }}</span>
                            @if ($item['free'])
                                <x-filament::badge color="success" size="sm">Free</x-filament::badge>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</x-dynamic-component>
