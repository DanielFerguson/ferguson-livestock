{{-- What the order cost. Expects $order with items.product. Prints with the letter above it; the forms and cards below hide. --}}
@php
    use App\Enums\OrderStatus;
    use App\Enums\PaymentMethod;
    use App\Support\Money;

    /** @var \App\Models\Order $order */
    $payment = match (true) {
        $order->status === OrderStatus::CashOwed => 'Cash, still to pay',
        $order->payment_method === PaymentMethod::Cash => 'Paid in cash',
        $order->status === OrderStatus::Processing => 'Bank payment, clearing',
        default => 'Paid online',
    };
@endphp

<section aria-labelledby="receipt-heading" class="border-t border-gray-100 pt-6 text-base">
    <div class="mb-3 flex items-baseline justify-between gap-4">
        <h2 id="receipt-heading" class="font-display text-2xl font-semibold text-forest">Receipt</h2>
        <button type="button" onclick="window.print()" class="cursor-pointer text-sm font-semibold text-forest underline print:hidden">Print or save as PDF</button>
    </div>

    <p class="mb-3">Order {{ $order->reference() }} · {{ ($order->paid_at ?? $order->created_at)->setTimezone(config('shop.timezone'))->format('j F Y') }} · {{ $payment }}</p>

    <table class="w-full">
        <caption class="sr-only">What you ordered</caption>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td class="py-1 pr-4">{{ $item->quantity > 1 ? $item->quantity.' × ' : '' }}{{ $item->product->name }}</td>
                    <td class="py-1 text-right">{{ $item->free ? 'Free' : Money::format($item->lineTotal()) }}</td>
                </tr>
            @endforeach
            <tr class="font-semibold text-forest">
                <td class="pt-3 pr-4">Total</td>
                <td class="pt-3 text-right">{{ Money::format($order->total) }}</td>
            </tr>
            @if ($order->amount_refunded > 0)
                <tr>
                    <td class="py-1 pr-4">Refunded</td>
                    <td class="py-1 text-right">−{{ Money::format($order->amount_refunded) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <p class="mt-3 text-sm text-gray-600">{{ config('shop.name') }} · {{ config('shop.location.locality') }}, {{ config('shop.location.region') }} · {{ config('shop.email') }}</p>
</section>
