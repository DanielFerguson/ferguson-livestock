{{-- The admin as its own app on the owner's phone: added to the Home Screen, it can get a push for each new text. --}}
<link rel="manifest" href="/admin.webmanifest">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<meta name="apple-mobile-web-app-title" content="Ferguson Livestock">
@auth
    <meta name="admin-attention-count" content="{{ \App\Sms\ConversationList::attentionCount() }}">
@endauth
@vite('resources/js/admin-push.js')
