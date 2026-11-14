{{-- Shown on every admin page while no phone is signed up for pushes, so texts can't go unseen without anyone knowing. --}}
@php
    $admin = filament()->auth()->user();
@endphp

@if ($admin instanceof \App\Models\User && filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key')) && $admin->pushSubscriptions()->doesntExist())
    <div class="fl-push-warning" role="status">
        No phone gets a notification for new texts, so check
        <a href="{{ \App\Filament\Pages\Conversations::getUrl() }}">Conversations</a> yourself. To turn them on, open
        Ferguson Livestock from your phone’s home screen and tap Turn on notifications in Conversations.
    </div>
@endif
