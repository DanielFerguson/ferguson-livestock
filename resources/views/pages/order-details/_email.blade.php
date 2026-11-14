{{-- Sends the order confirmation again, to the address on the order only. Expects $order. --}}
@if ($order->email !== null)
    <section id="email" aria-labelledby="email-heading" class="border border-forest/5 bg-white p-8 shadow-xl">
        <h2 id="email-heading" class="mb-3 font-display text-2xl font-semibold text-forest">Email me my order</h2>
        <p class="mb-5">We’ll send your order and receipt to {{ $order->emailHint() }}, the address you gave at checkout.</p>

        @if (session('order-page.email'))
            <p class="mb-5 border-l-4 border-sage bg-mint/10 p-4 text-forest" role="status">{{ session('order-page.email') }}</p>
        @endif

        <form method="post" action="{{ route('orders.email', ['customerOrder' => $order->access_token]) }}">
            @csrf
            <x-button variant="outline">
                <x-svg-icon name="mail" />
                Email it to me
            </x-button>
        </form>
    </section>
@endif
