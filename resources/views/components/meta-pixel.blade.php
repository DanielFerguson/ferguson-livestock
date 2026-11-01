@props([
    // Events after the PageView, each [name, parameters, event ID or null]. An event ID matches the server's copy of
    // the same event, so Meta counts the pair once.
    'events' => [],
])

{{-- Meta's pixel, as Meta gives it. The same for every visitor, so it can sit in cached pages. --}}
<script>
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
    n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
    document,'script','https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', @js((string) config('services.meta.pixel_id')));
    fbq('track', 'PageView');
    @foreach ($events as [$name, $parameters, $eventId])
        fbq('track', @js($name), @js((object) $parameters)@if ($eventId !== null), @js(['eventID' => $eventId])@endif);
    @endforeach
</script>
