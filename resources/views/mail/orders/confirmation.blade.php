<x-mail::message>
# Thanks{{ $order->firstName() !== null ? ', ' : '' }}{{ \App\Support\MarkdownText::escape($order->firstName()) }}!

{{-- Sent when the order is paid, and again whenever the customer asks for it from their page, so it says where the order is up to. --}}
@if ($order->isRefundedInFull())
Your order **{{ $order->reference() }}** has been refunded in full. It can take a few business days to reach your account.
@elseif ($order->status === \App\Enums\OrderStatus::Fulfilled)
Here’s your order **{{ $order->reference() }}**. Thanks again for buying from us.
@elseif ($order->status === \App\Enums\OrderStatus::Processing)
Your order **{{ $order->reference() }}** is waiting for your bank payment to clear, which usually takes a few business days. We’re holding it for you in the meantime.
@else
Your order **{{ $order->reference() }}** is paid and we’re getting it ready.
@endif

@include('mail.orders._items')

@unless ($order->isRefundedInFull())
@if ($order->delivery_method === \App\Enums\DeliveryMethod::Delivery)
**Delivery** on {{ $order->deliveryNight() }}, to:

{{ \App\Support\MarkdownText::escape($order->shipping_address['line1'] ?? null) }}@if (($order->shipping_address['line2'] ?? null) !== null), {{ \App\Support\MarkdownText::escape($order->shipping_address['line2']) }}@endif<br>
{{ \App\Support\MarkdownText::escape($order->shipping_address['city'] ?? null) }} {{ \App\Support\MarkdownText::escape($order->shipping_address['postal_code'] ?? null) }}

@if ($order->status !== \App\Enums\OrderStatus::Fulfilled)
We’ll be in touch to confirm a time. Everything arrives vacuum-sealed in insulated packaging: pop it straight in the freezer or fridge.
@endif
@else
**Farm pickup** from {{ config('shop.location.locality') }}, {{ $order->loadMissing('drop')->drop->pickupTimes() }}. Come up the driveway and park anywhere near the house, and one of us will be out to meet you.
@endif
@endunless

<x-mail::button :url="$order->pageUrl()">
View your order
</x-mail::button>

Questions? Reply to this email or call {{ config('shop.phone.display') }}.

With gratitude,<br>
Daniel & Tahlia, {{ config('shop.name') }}
</x-mail::message>
