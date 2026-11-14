@php
    use App\Enums\DeliveryMethod;
    use App\Enums\OrderStatus;

    /** @var \App\Models\Order $order */
    $firstName = $order->firstName() ?? 'friend';
    $subheading = match (true) {
        $order->status === OrderStatus::Processing => 'Your payment is on its way',
        $order->status === OrderStatus::Fulfilled && $order->delivery_method === DeliveryMethod::Delivery => 'Your order has been delivered',
        $order->status === OrderStatus::Fulfilled => 'You’ve collected your order',
        default => 'We’re getting your order ready',
    };
@endphp

{{-- The secret in this page's address is the key to the order, so the page stays away from Meta and search engines. --}}
<x-layouts.app
    :title="'Your order '.$order->reference().' | Ferguson Livestock'"
    description="Your Ferguson Livestock order."
    robots="noindex, nofollow"
    :fonts="['source-sans', 'cormorant', 'caveat']"
    :ad-tracking="false"
>
    <div class="bg-cream-dark px-6 py-16 print:bg-white print:py-0">
        <x-confirmation-letter :heading="'Thanks, '.$firstName.'!'" :subheading="$subheading">
            @if ($order->status === OrderStatus::Processing)
                <p>Your bank payment usually takes a few business days to clear. We’re holding your order in the meantime.</p>
            @elseif ($order->status === OrderStatus::Fulfilled)
                <p>Thank you for supporting our small family farm. We hope you enjoy every bit of it, and we’d love to hear how it went at the bottom of this page.</p>
            @else
                <p>Thank you for supporting our small family farm! We’re packing everything fresh and vacuum-sealing it, ready for you.</p>
            @endif
        </x-confirmation-letter>
    </div>
</x-layouts.app>
