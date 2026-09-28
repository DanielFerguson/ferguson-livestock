{{-- Choices are made in the browser; "Continue to payment" is the only request. See App\Livewire\OrderForm. --}}
<div x-data="orderForm(@js($items), @js($status->isOpen()))" x-on:drop-status.window="apply($event.detail)" class="space-y-8">
    @include('livewire.order-form.status')

    <form wire:submit="checkout" class="space-y-8" novalidate>
        @include('livewire.order-form.boxes')
        @include('livewire.order-form.extras')
        @include('livewire.order-form.delivery')
        @include('livewire.order-form.summary')
    </form>
</div>
