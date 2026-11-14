<x-mail::message>
# {{ $order->reference() }} moved to {{ $order->deliveryNight() }}

**{{ \App\Support\MarkdownText::escape($order->customer_name) }}** moved their delivery from {{ $previousNight }} to **{{ $order->deliveryNight() }}** on their order page.

If you’ve already planned that run, check it still works.
</x-mail::message>
