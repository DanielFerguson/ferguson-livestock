{{--
    The Notifications menu beside the Conversations heading. Each phone signs itself up, so what it shows depends on
    the phone: resources/js/admin-push.js runs it. wire:ignore keeps the page's 10-second refresh from closing it.
--}}
@php
    $pushKey = \App\Filament\Pages\Conversations::pushPublicKey();
@endphp

<div class="fl-push-menu" wire:ignore @if ($pushKey !== null) x-data="pushNotifications(@js($pushKey))" @endif>
    <x-filament::dropdown placement="bottom-end" width="xs">
        <x-slot name="trigger">
            <x-filament::button color="gray" size="sm" icon="heroicon-m-bell" wire:target="sendTestPush">
                Notifications
            </x-filament::button>
        </x-slot>

        <p class="fl-push-menu-status">
            @if ($pushKey === null)
                Notifications for new texts aren’t set up yet: the site needs VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY.
            @else
                <span x-show="state === 'checking' || state === 'busy'">One moment…</span>
                <span x-show="state === 'unsupported'" x-cloak>To get a notification for each new text, open this page in Safari on your iPhone, tap Share, then Add to Home Screen, and use Ferguson Livestock from your home screen.</span>
                <span x-show="state === 'denied'" x-cloak>Notifications are blocked on this phone. Turn them on in Settings → Notifications → Ferguson Livestock.</span>
                <span x-show="state === 'off'" x-cloak>This phone doesn’t get notifications for new texts.</span>
                <span x-show="state === 'on'" x-cloak>This phone gets a notification for each new text.</span>
                <span x-show="state === 'failed'" x-cloak>That didn’t work. Try again in a moment.</span>
            @endif
        </p>

        @if ($pushKey !== null)
            <x-filament::dropdown.list x-show="['off', 'failed', 'on'].includes(state)" x-cloak>
                <x-filament::dropdown.list.item icon="heroicon-m-bell" x-show="state === 'off' || state === 'failed'" x-cloak x-on:click="turnOn" wire:target="savePushSubscription">
                    Turn on notifications
                </x-filament::dropdown.list.item>
                <x-filament::dropdown.list.item icon="heroicon-m-paper-airplane" x-show="state === 'on'" x-cloak x-on:click="sendTest(); close()" wire:target="sendTestPush">
                    Send a test
                </x-filament::dropdown.list.item>
                <x-filament::dropdown.list.item icon="heroicon-m-bell-slash" x-show="state === 'on'" x-cloak x-on:click="turnOff" wire:target="removePushSubscription">
                    Turn off
                </x-filament::dropdown.list.item>
            </x-filament::dropdown.list>
        @endif
    </x-filament::dropdown>
</div>
