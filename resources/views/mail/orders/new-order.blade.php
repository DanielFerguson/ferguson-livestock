<x-mail::message>
# New order {{ $order->reference() }}

**{{ \App\Support\MarkdownText::escape($order->customer_name) }}** · {{ \App\Support\MarkdownText::escape($order->email) }} · {{ \App\Support\MarkdownText::escape($order->phone) }}

@include('mail.orders._items')

@if ($order->delivery_method === \App\Enums\DeliveryMethod::Delivery)
**Delivery** on {{ $order->delivery_day?->format('l j F') }} to {{ \App\Support\MarkdownText::escape($order->shipping_address['line1'] ?? null) }}@if (($order->shipping_address['line2'] ?? null) !== null), {{ \App\Support\MarkdownText::escape($order->shipping_address['line2']) }}@endif, {{ \App\Support\MarkdownText::escape($order->shipping_address['city'] ?? null) }} {{ \App\Support\MarkdownText::escape($order->shipping_address['postal_code'] ?? null) }}
@else
**Farm pickup**
@endif
</x-mail::message>
