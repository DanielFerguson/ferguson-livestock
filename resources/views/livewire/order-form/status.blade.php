@php
    use App\Enums\DropStatus;

    $timezone = config()->string('shop.timezone');
@endphp

<div class="space-y-3 text-lg">
    <p data-drop-show="scheduled" @if ($status !== DropStatus::Scheduled) hidden @endif>
        Orders open <time datetime="{{ $drop->opens_at->toIso8601ZuluString() }}">{{ $drop->opens_at->setTimezone($timezone)->format('l j F \a\t g:ia') }}</time>,
        in <span data-drop-countdown class="font-semibold tabular-nums"></span>.
    </p>
    <p data-drop-show="live" @if ($status !== DropStatus::Live) hidden @endif>Orders are open. Stock updates live as people order.</p>
    <p data-drop-show="sold_out" @if ($status !== DropStatus::SoldOut) hidden @endif>
        The boxes have sold out. <span data-drop-held>{{ $held }}</span> in checkout may free up, and any extras left can still be ordered.
    </p>
    <p data-drop-show="closed none" hidden>This drop has closed. Join the wait list and we’ll text you when the next one opens.</p>

    <p data-drop-paused hidden role="status" class="text-base text-warm-dark">Live updates paused, reconnecting…</p>

    <div aria-live="polite" class="space-y-2 text-base">
        @if ($cancelNotice !== null)
            <p class="border-l-4 border-sage bg-white px-4 py-3">{{ $cancelNotice }}</p>
        @endif
        @foreach ($notices as $notice)
            <p class="border-l-4 border-warm bg-white px-4 py-3">{{ $notice }}</p>
        @endforeach
        <template x-for="notice in notices" :key="notice">
            <p class="border-l-4 border-warm bg-white px-4 py-3" x-text="notice"></p>
        </template>
    </div>

    @if ($problem !== null)
        <p role="alert" class="border-l-4 border-red-700 bg-white px-4 py-3 text-base text-red-800">{{ $problem }}</p>
    @endif
</div>
