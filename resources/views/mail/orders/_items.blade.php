<x-mail::table>
| Item | Qty | Price |
| :--- | :-: | ---: |
@foreach ($order->items as $item)
| {{ $item->product->name }} | {{ $item->quantity }} | {{ \App\Support\Money::format($item->lineTotal()) }} |
@endforeach
| **Total** | | **{{ \App\Support\Money::format($order->total) }}** |
@if ($order->amount_refunded > 0)
| Refunded | | −{{ \App\Support\Money::format($order->amount_refunded) }} |
@endif
</x-mail::table>
