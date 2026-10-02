{{-- Cloudflare's bot check (see App\Support\Turnstile). Shows nothing until both Turnstile keys are set. --}}
@if (\App\Support\Turnstile::isEnabled())
    <div {{ $attributes->merge(['class' => 'cf-turnstile']) }} data-sitekey="{{ config('services.turnstile.site_key') }}" data-size="flexible" data-theme="light"></div>
    @once
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endonce
@endif
