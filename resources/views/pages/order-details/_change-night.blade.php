{{-- The delivery night picker. Expects $order (with its drop) and $nightChoices (see ChangeDeliveryNight::choicesFor()). --}}
@php
    use App\Actions\Orders\ChangeDeliveryNight;
    use Carbon\CarbonImmutable;

    /** @var \App\Models\Order $order */
    /** @var list<string> $nightChoices */
    $current = $order->delivery_day?->toDateString() ?? ChangeDeliveryNight::EITHER;
    $nights = array_values(array_filter(
        [...$order->drop->delivery_days, ChangeDeliveryNight::EITHER],
        fn (string $night): bool => $night === $current || in_array($night, $nightChoices, true),
    ));
    $label = fn (string $night): string => $night === ChangeDeliveryNight::EITHER
        ? 'Either night suits me'
        : CarbonImmutable::parse($night)->format('l').' night, '.CarbonImmutable::parse($night)->format('j F');
@endphp

<form method="post" action="{{ route('orders.night', ['customerOrder' => $order->access_token]) }}" class="mt-5 print:hidden">
    @csrf

    <fieldset>
        <legend class="mb-2 font-semibold text-forest">Need a different night?</legend>

        @foreach ($nights as $night)
            <label class="flex items-center gap-3 py-1">
                <input type="radio" name="night" value="{{ $night }}" @checked($night === $current) class="h-5 w-5 accent-forest">
                {{ $label($night) }}
            </label>
        @endforeach
    </fieldset>

    <p class="mt-2 text-sm text-gray-600">You can change it until 12pm the day before.</p>

    <x-button variant="outline" class="mt-4">Change night</x-button>
</form>
