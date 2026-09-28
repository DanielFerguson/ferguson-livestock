<x-mail::message>
# New order {{ $order->reference() }}

**{{ $order->customer_name }}** · {{ $order->email }} · {{ $order->phone }}

@include('mail.orders._items')

@if ($order->delivery_method === \App\Enums\DeliveryMethod::Delivery)
**Delivery** on {{ $order->delivery_day?->format('l j F') }} to {{ $order->shipping_address['line1'] ?? '' }}@if (($order->shipping_address['line2'] ?? null) !== null), {{ $order->shipping_address['line2'] }}@endif, {{ $order->shipping_address['city'] ?? '' }} {{ $order->shipping_address['postal_code'] ?? '' }}
@else
**Farm pickup**
@endif
</x-mail::message>
