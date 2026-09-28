<x-mail::message>
# Thanks{{ $order->firstName() !== null ? ', '.$order->firstName() : '' }}!

Your order **{{ $order->reference() }}** is paid and we’re getting it ready.

@include('mail.orders._items')

@if ($order->delivery_method === \App\Enums\DeliveryMethod::Delivery)
**Delivery** on {{ $order->delivery_day?->format('l j F') }}, in the afternoon or evening, to:

{{ $order->shipping_address['line1'] ?? '' }}@if (($order->shipping_address['line2'] ?? null) !== null), {{ $order->shipping_address['line2'] }}@endif<br>
{{ $order->shipping_address['city'] ?? '' }} {{ $order->shipping_address['postal_code'] ?? '' }}

We’ll be in touch to confirm a time. Everything arrives vacuum-sealed in insulated packaging: pop it straight in the freezer or fridge.
@else
**Farm pickup** from {{ config('shop.location.locality') }}. We’ll be in touch to arrange a time to collect.
@endif

Questions? Reply to this email or call {{ config('shop.phone.display') }}.

With gratitude,<br>
Daniel & Tahlia, {{ config('shop.name') }}
</x-mail::message>
