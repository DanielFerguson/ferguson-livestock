@php
    use App\Enums\DeliveryMethod;
    use App\Enums\OrderStatus;
    use App\Enums\ProductType;
    use App\Support\Money;

    /** @var \App\Models\Order $order */
    $firstName = $order->firstName() ?? 'friend';
    $hasBox = $order->items->contains(fn ($item) => $item->product->type === ProductType::Box);
    [$heading, $subheading] = match ($order->status) {
        OrderStatus::Pending => ["Thanks, {$firstName}!", 'We’re confirming your payment'],
        OrderStatus::Processing => ["Thanks, {$firstName}!", 'Your payment is on its way'],
        default => ["Order confirmed, {$firstName}!", $hasBox ? 'Your beef box is being prepared' : 'Your order is being prepared'],
    };
@endphp

<x-layouts.app
    title="Order Confirmed | Ferguson Livestock"
    description="Thanks for your order from Ferguson Livestock."
    robots="noindex, follow"
    :fonts="['source-sans', 'cormorant', 'caveat']"
>
    <div class="flex min-h-[80vh] items-center justify-center bg-cream-dark px-6 py-16">
        <x-confirmation-letter :heading="$heading" :subheading="$subheading">
            @if ($order->status === OrderStatus::Pending)
                <p>We’re just confirming your payment with Stripe. You’ll get an email as soon as it’s through, so there’s no need to pay again.</p>
            @elseif ($order->status === OrderStatus::Processing)
                <p>Your bank payment usually takes a few business days to clear. We’re holding your order in the meantime and will email you once it’s through.</p>
            @else
                <p>Thank you for supporting our small family farm! Your order is paid and we’re getting everything ready for you.@if ($order->email !== null) A confirmation is on its way to {{ $order->email }}.@endif</p>
            @endif

            <div class="border-y border-gray-100 py-6 text-base">
                <p class="mb-3 font-semibold text-forest">Order {{ $order->reference() }}</p>
                <table class="w-full">
                    <tbody>
                        @foreach ($order->items as $item)
                            <tr>
                                <td class="py-1 pr-4">{{ $item->quantity > 1 ? $item->quantity.' × ' : '' }}{{ $item->product->name }}</td>
                                <td class="py-1 text-right">{{ Money::format($item->lineTotal()) }}</td>
                            </tr>
                        @endforeach
                        <tr class="font-semibold text-forest">
                            <td class="pt-3 pr-4">Total</td>
                            <td class="pt-3 text-right">{{ Money::format($order->total) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            @if ($order->delivery_method === DeliveryMethod::Delivery)
                <p>We’ll pack everything fresh, vacuum-seal it and deliver it on {{ $order->delivery_day?->format('l j F') }}, in the afternoon or evening. We’ll be in touch to confirm a time. Everything arrives in insulated packaging, ready to go straight in the freezer or fridge.</p>
            @else
                <p>We’ll pack everything fresh and vacuum-seal it, ready for you to collect from our farm in {{ config('shop.location.locality') }}. We’ll be in touch to arrange a time.</p>
            @endif

            <p>If you have any questions in the meantime, reply to your confirmation email or call us on <a class="underline" href="tel:{{ config('shop.phone.international') }}">{{ config('shop.phone.display') }}</a>.</p>

            <x-slot:actions>
                <div class="flex justify-center">
                    <x-button :href="route('home')">
                        <x-svg-icon name="home" />
                        Back to the homepage
                    </x-button>
                </div>
            </x-slot:actions>
        </x-confirmation-letter>
    </div>
</x-layouts.app>
