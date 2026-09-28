<x-mail::table>
| Item | Qty | Price |
| :--- | :-: | ---: |
@foreach ($order->items as $item)
| {{ $item->product->name }} | {{ $item->quantity }} | {{ \App\Support\Money::format($item->lineTotal()) }} |
@endforeach
| **Total** | | **{{ \App\Support\Money::format($order->total) }}** |
</x-mail::table>
