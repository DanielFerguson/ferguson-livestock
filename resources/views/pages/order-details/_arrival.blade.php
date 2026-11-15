{{-- On a pickup day, lets the customer tell the farm they've arrived (see AnnounceArrival). Expects $order and $canAnnounceArrival. --}}
<section id="arrival" aria-labelledby="arrival-heading" class="border-l-4 border-sage bg-mint/10 p-6">
    <h2 id="arrival-heading" class="mb-2 font-display text-2xl font-semibold text-forest">Here to collect?</h2>
    <p>Come up the driveway and park anywhere near the house, then tap the button. Dan or Tahlia will be out to greet you with your beef.</p>

    @if (session('order-page.arrival'))
        <p class="mt-4 font-semibold text-forest" role="status">{{ session('order-page.arrival') }}</p>
    @endif

    @if ($canAnnounceArrival)
        <form method="post" action="{{ route('orders.arrived', ['customerOrder' => $order->access_token]) }}" class="mt-5">
            @csrf
            <x-button>We’re here</x-button>
        </form>
    @endif
</section>
