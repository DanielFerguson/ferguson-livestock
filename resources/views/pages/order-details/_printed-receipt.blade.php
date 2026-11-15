{{--
    The receipt as it prints or saves as a PDF, in the plain style of Stripe's: the rest of the page hides when printing.
    Expects $order with items.product and drop, and $payment.

    The page has no margins, so browsers leave off their header and footer, which would print this page's address, and
    with it the key to the order.
--}}
@php
    use App\Enums\DeliveryMethod;
    use App\Enums\OrderStatus;
    use App\Enums\PaymentMethod;
    use App\Support\Money;

    /** @var \App\Models\Order $order */
    $timezone = config('shop.timezone');
    $isPaid = $order->paid_at !== null && $order->status !== OrderStatus::CashOwed;
    $date = ($isPaid ? $order->paid_at : $order->created_at)->setTimezone($timezone)->format('j F Y');
    $address = $order->shipping_address;

    $status = match (true) {
        $order->isRefundedInFull() => 'Refunded'.($order->refunded_at === null ? '' : ' '.$order->refunded_at->setTimezone($timezone)->format('j F Y')),
        $isPaid => "Paid {$date}",
        default => $payment,
    };
@endphp

<div class="hidden text-[13px] leading-normal text-gray-600 print:block">
    <style>
        @page { margin: 0; }
        @media print { body { background: #fff; } }
    </style>

    <div class="mx-auto max-w-lg px-10 py-14">
        <p class="font-display text-2xl font-semibold text-forest">{{ config('shop.name') }}</p>

        <p class="mt-10 text-gray-500">Receipt from {{ config('shop.name') }}</p>
        <p class="mt-1 text-3xl font-semibold text-gray-900">{{ Money::format($order->total) }}</p>
        <p class="mt-1 text-gray-500">{{ $status }}</p>

        <dl class="mt-8 grid grid-cols-3 gap-6">
            <div>
                <dt class="text-[11px] font-semibold tracking-wide text-gray-500 uppercase">Receipt number</dt>
                <dd class="mt-1 text-gray-900">{{ $order->reference() }}</dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold tracking-wide text-gray-500 uppercase">{{ $isPaid ? 'Date paid' : 'Date ordered' }}</dt>
                <dd class="mt-1 text-gray-900">{{ $date }}</dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold tracking-wide text-gray-500 uppercase">Payment method</dt>
                <dd class="mt-1 text-gray-900">{{ $order->payment_method === PaymentMethod::Cash ? 'Cash' : 'Online' }}</dd>
            </div>
        </dl>

        <h2 class="mt-8 text-[11px] font-semibold tracking-wide text-gray-500 uppercase">Summary</h2>
        <div class="mt-2 rounded-md bg-gray-50 px-5 py-2" style="-webkit-print-color-adjust: exact; print-color-adjust: exact;">
            <table class="w-full">
                <tbody>
                    @foreach ($order->items as $item)
                        <tr class="border-b border-gray-200">
                            <td class="py-3 pr-4 align-top">
                                <span class="text-gray-900">{{ $item->product->name }}</span>
                                @if ($item->quantity > 1)
                                    <span class="block text-gray-500">Qty {{ $item->quantity }}{{ $item->free ? '' : ' × '.Money::format($item->unit_price) }}</span>
                                @endif
                            </td>
                            <td class="py-3 text-right align-top text-gray-900">{{ $item->free ? 'Free' : Money::format($item->lineTotal()) }}</td>
                        </tr>
                    @endforeach
                    <tr>
                        <td class="pt-3 pr-4 font-semibold text-gray-900">Total</td>
                        <td class="pt-3 text-right font-semibold text-gray-900">{{ Money::format($order->total) }}</td>
                    </tr>
                    @if ($isPaid)
                        <tr>
                            <td class="pt-1 pr-4">Amount paid</td>
                            <td class="pt-1 text-right">{{ Money::format($order->total) }}</td>
                        </tr>
                    @endif
                    @if ($order->amount_refunded > 0)
                        <tr>
                            <td class="pt-1 pr-4">Refunded</td>
                            <td class="pt-1 text-right">−{{ Money::format($order->amount_refunded) }}</td>
                        </tr>
                    @endif
                    <tr><td class="pb-2" colspan="2"></td></tr>
                </tbody>
            </table>
        </div>

        <p class="mt-6">
            @if ($order->delivery_method === DeliveryMethod::Delivery)
                Delivery{{ $address === null ? '' : ' to '.collect([$address['line1'] ?? null, $address['line2'] ?? null, trim(($address['city'] ?? '').' '.($address['postal_code'] ?? ''))])->filter()->implode(', ') }}.
            @else
                Farm pickup at {{ config('shop.location.street') }}, {{ config('shop.location.locality') }}.
            @endif
        </p>

        <div class="mt-10 border-t border-gray-200 pt-6 text-gray-500">
            <p>Questions? Email {{ config('shop.email') }} or text {{ config('shop.phone.display') }}.</p>
            <p class="mt-1">{{ config('shop.name') }} · {{ config('shop.location.street') }}, {{ config('shop.location.locality') }} {{ config('shop.location.region') }} {{ config('shop.location.postal_code') }}</p>
        </div>
    </div>
</div>
