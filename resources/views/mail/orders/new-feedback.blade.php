<x-mail::message>
# {{ $feedback->stars() }} for {{ $order->reference() }}

**{{ \App\Support\MarkdownText::escape($order->customer_name) }}** {{ $edited ? 'changed their feedback' : 'left feedback' }} on their order page.

@if ($feedback->comment !== null)
{{ \App\Support\MarkdownText::escape($feedback->comment) }}
@else
No comment, just the stars.
@endif
</x-mail::message>
