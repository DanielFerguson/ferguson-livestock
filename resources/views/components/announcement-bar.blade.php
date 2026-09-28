{{-- Shown above the homepage header. The live stock script swaps the message as the drop's state changes. --}}
@php
    $state = $liveDrop['drop']['state'] ?? 'none';
    $opensAt = isset($liveDrop['drop']) ? \Carbon\CarbonImmutable::parse($liveDrop['drop']['opens_at'])->setTimezone(config()->string('shop.timezone')) : null;
    $announcedLabel = $liveDrop['announced']['label'] ?? null;
    // An announced date stands in for "coming soon" between drops, and adds to the sold-out message.
    $showAnnounced = $announcedLabel !== null && in_array($state, ['none', 'closed'], true);
    $link = 'text-cream underline underline-offset-4 hover:text-mint-light';
@endphp

<div class="bg-linear-to-br from-forest to-forest-light px-6 py-3 text-center text-sm font-medium tracking-wide text-cream">
    <p data-drop-show="live" @if ($state !== 'live') hidden @endif>
        <span class="font-semibold text-mint-light">Orders are open</span>
        <span aria-hidden="true">·</span>
        <a href="{{ route('order') }}" class="{{ $link }}">Order now</a>
    </p>
    <p data-drop-show="sold_out" @if ($state !== 'sold_out') hidden @endif>
        <span class="font-semibold text-mint-light">Boxes have sold out</span>
        <span aria-hidden="true">·</span>
        <a href="{{ route('order') }}" class="{{ $link }}">See what’s left</a>
        @if ($announcedLabel !== null)
            <span data-drop-announced><span aria-hidden="true">·</span> Next drop {{ $announcedLabel }}</span>
        @endif
    </p>
    <p data-drop-show="scheduled" @if ($state !== 'scheduled') hidden @endif>
        <span class="font-semibold text-mint-light">Next drop opens {{ $opensAt?->format('l j F \a\t g:ia') }}</span>
        <span aria-hidden="true">·</span>
        <a href="#waitlist" class="{{ $link }}">Join the wait list</a>
    </p>
    @if ($announcedLabel !== null)
        <p data-drop-show="announced" @if (! $showAnnounced) hidden @endif>
            <span class="font-semibold text-mint-light">Next drop: {{ $announcedLabel }}</span>
            <span aria-hidden="true">·</span>
            <a href="#waitlist" class="{{ $link }}">Join the wait list</a>
        </p>
    @endif
    <p data-drop-show="closed none" @if (in_array($state, ['live', 'sold_out', 'scheduled'], true) || $showAnnounced) hidden @endif>
        <span class="font-semibold text-mint-light">Next drop coming soon</span>
        <span aria-hidden="true">·</span>
        <a href="#waitlist" class="{{ $link }}">Join the wait list</a>
    </p>
</div>
