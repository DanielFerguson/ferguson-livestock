@php
    use App\Support\AustralianMobile;
    use App\Support\DeliveryRun;

    $run = $this->run();
@endphp

<x-filament-panels::page>
    {{-- Plain CSS: Filament's stylesheet only has the classes its own components use. --}}
    <style>
        .run-day { margin: 1.25rem 0 0.25rem; font-weight: 600; }
        .run-day:first-child { margin-top: 0; }
        .run-order { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1.4fr); gap: 1rem; padding: 0.75rem 0; border-top: 1px solid rgb(0 0 0 / 0.08); }
        .run-order:first-of-type { border-top: 0; }
        .run-muted { color: rgb(107 114 128); font-size: 0.875rem; }
        @media (max-width: 640px) { .run-order { grid-template-columns: minmax(0, 1fr); gap: 0.25rem; } }
        @media print {
            .fi-sidebar, .fi-topbar, .fi-breadcrumbs, .run-print-button { display: none !important; }
            .fi-main { padding: 0 !important; }
            .run-order { break-inside: avoid; }
        }
    </style>

    <div class="run-print-button">
        <x-filament::button icon="heroicon-o-printer" onclick="window.print()">Print</x-filament::button>
    </div>

    <x-filament::section heading="Deliveries" :description="$run->deliveries->count().' '.str('order')->plural($run->deliveries->count())">
        @forelse ($run->deliveries->groupBy(fn ($order) => $order->delivery_day?->format('l j F') ?? 'No day') as $day => $orders)
            <p class="run-day">{{ $day }}</p>
            @foreach ($orders as $order)
                <div class="run-order">
                    <div>
                        <strong>{{ $order->customer_name }}</strong><br>
                        {{ $order->phone === null ? '' : AustralianMobile::readable($order->phone) }}<br>
                        <span class="run-muted">{{ $order->reference() }}</span>
                    </div>
                    <div>
                        {{ $order->shipping_address['line1'] ?? '' }}@if (($order->shipping_address['line2'] ?? null) !== null), {{ $order->shipping_address['line2'] }}@endif<br>
                        <strong>{{ $order->shipping_address['city'] ?? '' }}</strong> {{ $order->shipping_address['postal_code'] ?? '' }}
                    </div>
                    <div>{{ DeliveryRun::items($order) }}</div>
                </div>
            @endforeach
        @empty
            <p>No paid deliveries yet.</p>
        @endforelse
    </x-filament::section>

    <x-filament::section heading="Farm pickups" :description="$run->pickups->count().' '.str('order')->plural($run->pickups->count())">
        @forelse ($run->pickups as $order)
            <div class="run-order">
                <div>
                    <strong>{{ $order->customer_name }}</strong><br>
                    {{ $order->phone === null ? '' : AustralianMobile::readable($order->phone) }}<br>
                    <span class="run-muted">{{ $order->reference() }}</span>
                </div>
                <div style="grid-column: span 2">{{ DeliveryRun::items($order) }}</div>
            </div>
        @empty
            <p>No paid pickups yet.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
