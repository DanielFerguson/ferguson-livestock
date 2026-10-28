@php
    use App\Enums\SmsDirection;
    use App\Sms\OptOutKeywords;
    use App\Support\AustralianMobile;

    $conversations = $this->conversations();
    $optedOut = $this->hasOptedOut();
    $timezone = config('shop.timezone');
@endphp

<x-filament-panels::page>
    {{-- Plain CSS: Filament's stylesheet only has the classes its own components use. --}}
    <style>
        .chat { display: grid; grid-template-columns: minmax(0, 20rem) minmax(0, 1fr); gap: 1rem; align-items: start; }
        .chat-list, .chat-thread { border: 1px solid rgb(0 0 0 / 0.1); border-radius: 0.75rem; background: white; }
        .chat-list-search { display: flex; gap: 0.5rem; padding: 0.75rem; border-bottom: 1px solid rgb(0 0 0 / 0.08); }
        .chat-search-box { flex: 1 1 auto; min-width: 0; }
        .chat-filter-box { flex: none; width: 9.5rem; }
        .chat-flag { flex: none; color: rgb(180 83 9); font-size: 0.75rem; font-weight: 400; }
        .chat-head-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 0.75rem; }
        .chat-head-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .chat-person { display: block; width: 100%; text-align: left; padding: 0.75rem 1rem; border: 0; border-bottom: 1px solid rgb(0 0 0 / 0.06); background: transparent; cursor: pointer; }
        .chat-person:hover, .chat-person-open { background: rgb(0 0 0 / 0.04); }
        .chat-person-top { display: flex; justify-content: space-between; gap: 0.5rem; font-weight: 600; }
        .chat-person-last { display: block; margin-top: 0.125rem; color: rgb(107 114 128); font-size: 0.875rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .chat-tag { flex: none; padding: 0 0.5rem; border-radius: 9999px; background: rgb(0 0 0 / 0.08); color: rgb(75 85 99); font-size: 0.75rem; font-weight: 400; line-height: 1.25rem; }
        .chat-dot { flex: none; min-width: 1.25rem; padding: 0 0.375rem; border-radius: 9999px; background: rgb(217 119 6); color: white; font-size: 0.75rem; line-height: 1.25rem; text-align: center; }
        .chat-empty { padding: 1.5rem 1rem; color: rgb(107 114 128); }
        .chat-head { padding: 0.875rem 1rem; border-bottom: 1px solid rgb(0 0 0 / 0.08); font-weight: 600; }
        .chat-head small { display: block; font-weight: 400; color: rgb(107 114 128); }
        .chat-messages { display: flex; flex-direction: column; gap: 0.625rem; max-height: min(36rem, 60vh); min-height: 8rem; overflow-y: auto; padding: 1rem; }
        .chat-bubble { max-width: 80%; padding: 0.5rem 0.75rem; border-radius: 0.75rem; text-align: left; overflow-wrap: anywhere; }
        .chat-text { display: block; white-space: pre-wrap; }
        .chat-in { align-self: flex-start; background: rgb(0 0 0 / 0.06); }
        .chat-out { align-self: flex-end; background: rgb(209 224 212); }
        .chat-meta { margin-top: 0.25rem; color: rgb(107 114 128); font-size: 0.75rem; }
        .chat-note { margin-top: 0.25rem; color: rgb(180 83 9); font-size: 0.75rem; }
        .chat-compose { padding: 0.75rem 1rem 1rem; border-top: 1px solid rgb(0 0 0 / 0.08); }
        .chat-compose textarea { width: 100%; border: 1px solid rgb(0 0 0 / 0.15); border-radius: 0.5rem; padding: 0.5rem 0.75rem; }
        .chat-compose-bar { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-top: 0.5rem; }
        .chat-hint { color: rgb(107 114 128); font-size: 0.875rem; }
        @media (max-width: 768px) { .chat { grid-template-columns: minmax(0, 1fr); } }
    </style>

    <div class="chat" wire:poll.10s="refreshOpen">
        <div class="chat-list">
            <div class="chat-list-search">
                <x-filament::input.wrapper class="chat-search-box">
                    <x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="Search" />
                </x-filament::input.wrapper>
                <x-filament::input.wrapper class="chat-filter-box">
                    <x-filament::input.select wire:model.live="filter" aria-label="Show">
                        @foreach ($this->filterOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            @forelse ($conversations as $conversation)
                <button type="button" wire:key="person-{{ $conversation['phone'] }}" wire:click="open('{{ $conversation['phone'] }}')" @class(['chat-person', 'chat-person-open' => $conversation['phone'] === $phone])>
                    <span class="chat-person-top">
                        <span>{{ $conversation['name'] ?? AustralianMobile::format($conversation['phone']) }}</span>
                        @if ($conversation['unread'] > 0)
                            <span class="chat-dot">{{ $conversation['unread'] }}</span>
                        @elseif ($conversation['followUp'])
                            <span class="chat-flag">Come back to</span>
                        @elseif ($conversation['needsReply'])
                            <span class="chat-flag">Needs reply</span>
                        @elseif ($conversation['optedOut'])
                            <span class="chat-tag">Opted out</span>
                        @endif
                    </span>
                    <span class="chat-person-last">{{ $conversation['last']->direction === SmsDirection::Outbound ? 'You: ' : '' }}{{ $conversation['last']->body }}</span>
                </button>
            @empty
                <p class="chat-empty">{{ $search === '' && $filter === 'all' ? 'Nobody has texted yet. Replies to your broadcasts show up here.' : 'No conversations match that.' }}</p>
            @endforelse
        </div>

        <div class="chat-thread">
            @if ($phone === null)
                <p class="chat-empty">Choose a conversation to read it and answer.</p>
            @else
                @php($messages = $this->thread($phone))
                @php($standing = $this->standing())
                <div class="chat-head">
                    <div class="chat-head-top">
                        <div>
                            {{ $this->subscriber()?->first_name ?? AustralianMobile::format($phone) }}
                            @if ($optedOut) <span class="chat-tag">Opted out</span> @endif
                            @if ($this->subscriber()?->first_name !== null)
                                <small>{{ AustralianMobile::format($phone) }}</small>
                            @endif
                        </div>
                        <div class="chat-head-actions">
                            @if ($standing['followUp'] ?? false)
                                <x-filament::button size="sm" color="gray" wire:click="clearFollowUp">Clear flag</x-filament::button>
                            @else
                                <x-filament::button size="sm" color="gray" icon="heroicon-o-flag" wire:click="followUp">Come back to</x-filament::button>
                            @endif
                            @if ($standing['attention'] ?? false)
                                <x-filament::button size="sm" icon="heroicon-o-check" wire:click="done">Done</x-filament::button>
                            @endif
                        </div>
                    </div>
                </div>

                @php($messages = $this->thread($phone))
                <div class="chat-messages" wire:key="thread-{{ $phone }}-{{ $messages->count() }}" x-data x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)">
                    @foreach ($messages as $message)
                        @php($inbound = $message->direction === SmsDirection::Inbound)
                        <div wire:key="message-{{ $message->id }}" @class(['chat-bubble', 'chat-in' => $inbound, 'chat-out' => ! $inbound])>
                            <span class="chat-text">{{ $message->body }}</span>
                            <div class="chat-meta">
                                {{ $message->created_at->timezone($timezone)->format('D j M, g:ia') }}
                                @if ($message->sms_broadcast_id !== null) · Broadcast @endif
                                @unless ($inbound) · {{ $message->status->getLabel() }} @endunless
                            </div>
                            @if ($inbound && OptOutKeywords::matches($message->body))
                                <div class="chat-note">Asked to stop, so they were opted out.</div>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="chat-compose">
                    @if ($optedOut)
                        <p class="chat-hint">They’ve opted out of texts, so you can’t text them.</p>
                    @else
                        <textarea wire:model.live.debounce.400ms="draft" rows="3" placeholder="Write an answer. It goes from the shop’s number."></textarea>
                        <div class="chat-compose-bar">
                            <span class="chat-hint">{{ $this->draftHint() }}</span>
                            <x-filament::button wire:click="send" wire:loading.attr="disabled" wire:target="send">Send</x-filament::button>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
