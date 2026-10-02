{{-- Choices are made in the browser; "Continue to payment" is the only request. See App\Livewire\OrderForm. --}}
<div x-data="orderForm(@js($items), @js($status->isOpen()))" x-on:drop-status.window="apply($event.detail)" x-on:turnstile-reset.window="resetTurnstile()" class="space-y-8">
    @include('livewire.order-form.status')

    <form wire:submit="checkout(turnstileToken())" class="space-y-8" novalidate>
        @include('livewire.order-form.boxes')
        @include('livewire.order-form.extras')
        @include('livewire.order-form.delivery')
        @include('livewire.order-form.summary')
    </form>
</div>
