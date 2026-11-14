{{-- How the order reaches the customer. Expects $order (with its drop) and $textUsUrl. --}}
@php
    use App\Enums\DeliveryMethod;

    /** @var \App\Models\Order $order */
    $address = $order->shipping_address;
@endphp

<section id="delivery" aria-labelledby="delivery-heading" class="border-t border-gray-100 pt-6">
    @if ($order->delivery_method === DeliveryMethod::Delivery)
        <h2 id="delivery-heading" class="mb-3 font-display text-2xl font-semibold text-forest">Delivery</h2>
        <p>We’ll bring it to you after 4pm on <strong>{{ $order->deliveryNight() }}</strong>, and we’ll text you closer to the time. Everything arrives vacuum-sealed in insulated packaging, ready for the freezer or fridge.</p>

        @if ($address !== null)
            <address class="mt-3 not-italic">
                {{ $address['line1'] ?? '' }}@if (filled($address['line2'] ?? null)), {{ $address['line2'] }}@endif<br>
                {{ $address['city'] ?? '' }} {{ $address['postal_code'] ?? '' }}
            </address>
        @endif
    @else
        <h2 id="delivery-heading" class="mb-3 font-display text-2xl font-semibold text-forest">Farm pickup</h2>
        <p>Collect it from {{ config('shop.location.street') }}, {{ config('shop.location.locality') }}, {{ $order->drop->pickupTimes() }}.</p>

        <div class="mt-4 print:hidden">
            <x-button :href="route('farm')" variant="outline">
                Open in Google Maps
                <x-svg-icon name="arrow-right" />
            </x-button>
        </div>
    @endif

    <p class="mt-4 text-base print:hidden">Need to switch between pickup and delivery, or change your address? <a class="underline" href="{{ $textUsUrl }}">Text us</a> and we’ll sort it out.</p>
</section>
