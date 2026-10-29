<x-mail::message>
# Paid too late: order {{ $order->reference() }}

**{{ \App\Support\MarkdownText::escape($order->customer_name) }}** · {{ \App\Support\MarkdownText::escape($order->email) }} · {{ \App\Support\MarkdownText::escape($order->phone) }}

They paid after their checkout had timed out, and what they chose has since been bought by someone else, so there is no order for them.

@if ($refunded)
**We have refunded {{ \App\Support\Money::format($order->total) }} to their card.** You don't need to do anything, but you may want to let them know.
@else
**Their payment has not been refunded.** Refund it in Stripe (payment {{ $order->stripe_payment_intent_id ?? 'unknown' }}), or sort out their order by hand.
@endif

@include('mail.orders._items')
</x-mail::message>
