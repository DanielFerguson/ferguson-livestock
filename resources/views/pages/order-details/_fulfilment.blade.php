{{-- How the order reaches the customer. Expects $order (with its drop), $textUsUrl and $nightChoices (see ChangeDeliveryNight::choicesFor()). --}}
@php
    use App\Enums\DeliveryMethod;

    /** @var \App\Models\Order $order */
    $address = $order->shipping_address;
    $isRefundedInFull = $order->isRefundedInFull();
@endphp

<section id="delivery" aria-labelledby="delivery-heading" class="border-t border-gray-100 pt-6">
    @if ($order->delivery_method === DeliveryMethod::Delivery)
        <h2 id="delivery-heading" class="mb-3 font-display text-2xl font-semibold text-forest">Delivery</h2>
        @if ($isRefundedInFull)
            <p>This order has been refunded, so there’s nothing to deliver.</p>
        @else
            <p>We’ll bring it to you after 4pm on <strong>{{ $order->deliveryNight() }}</strong>, and we’ll text you closer to the time. Everything arrives vacuum-sealed in insulated packaging, ready for the freezer or fridge.</p>

            @if ($address !== null)
                <address class="mt-3 not-italic">
                    {{ $address['line1'] ?? '' }}@if (filled($address['line2'] ?? null)), {{ $address['line2'] }}@endif<br>
                    {{ $address['city'] ?? '' }} {{ $address['postal_code'] ?? '' }}
                </address>
            @endif
        @endif

        @if (session('order-page.night'))
            <p class="mt-4 border-l-4 border-sage bg-mint/10 p-4 text-forest" role="status">{{ session('order-page.night') }}</p>
        @endif

        @if ($errors->getBag('night')->has('night'))
            <p class="mt-4 border-l-4 border-red-700 bg-red-50 p-4 text-red-900" role="alert">{{ $errors->getBag('night')->first('night') }}</p>
        @endif

        @if ($nightChoices !== [])
            @include('pages.order-details._change-night')
        @endif
    @else
        <h2 id="delivery-heading" class="mb-3 font-display text-2xl font-semibold text-forest">Farm pickup</h2>
        @if ($isRefundedInFull)
            <p>This order has been refunded, so there’s nothing to collect.</p>
        @else
            <p>Collect it from {{ config('shop.location.street') }}, {{ config('shop.location.locality') }}, {{ $order->drop->pickupTimes() }}.</p>

            <div class="mt-4 print:hidden">
                <x-button :href="route('farm')" variant="outline" target="_blank" rel="noopener">
                    Open in Google Maps
                    <x-svg-icon name="arrow-right" />
                </x-button>
            </div>
        @endif
    @endif

    @unless ($isRefundedInFull)
        <p class="mt-4 text-base print:hidden">Need to switch between pickup and delivery, or change your address? <a class="underline" href="{{ $textUsUrl }}">Text us</a> and we’ll sort it out.</p>
    @endunless
</section>
