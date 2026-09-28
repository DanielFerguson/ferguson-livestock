@php
    $firstName = session('waitlist.first_name');
    $siteUrl = config()->string('shop.url');
    $shareText = 'I just joined the wait list for farm-fresh Murray Grey beef from Ferguson Livestock. Have a look: '.$siteUrl;
@endphp

<x-layouts.app
    title="Thank You | Ferguson Livestock"
    description="Thanks for joining our wait list! We'll be in touch when the next beef drop is ready."
    robots="noindex, follow"
    :fonts="['source-sans', 'cormorant', 'caveat']"
>
    <div class="flex min-h-[80vh] items-center justify-center bg-cream-dark px-6 py-16">
        <x-confirmation-letter :heading="'Thanks for joining, '.($firstName ?? 'friend').'!'" subheading="You’re now on our wait list.">
            <p>We’re thrilled to have you join the Ferguson Livestock family. It means the world to us that you’re interested in supporting our small family farm.</p>
            <p>Here’s what happens next: when our next beef drop is ready, we’ll send you a text message with all the details and a link to order.</p>
            <p>No spam and no weekly newsletters—just a friendly heads-up when it’s time to stock your freezer.</p>
            <p>In the meantime, feel free to share this with friends and family who might be interested. The more, the merrier!</p>

            <x-slot:actions>
                <div class="flex flex-col justify-center gap-3 sm:flex-row sm:flex-wrap">
                    <x-button :href="route('home')">
                        <x-svg-icon name="home" />
                        Back to the homepage
                    </x-button>
                    <x-button href="sms:?&body={{ rawurlencode($shareText) }}" variant="outline">
                        <x-svg-icon name="message" />
                        Text a friend
                    </x-button>
                    <x-button href="mailto:?subject={{ rawurlencode('Farm-fresh beef from Ferguson Livestock') }}&body={{ rawurlencode($shareText) }}" variant="outline">
                        <x-svg-icon name="mail" />
                        Email a friend
                    </x-button>
                    <x-button href="https://www.facebook.com/sharer/sharer.php?u={{ rawurlencode($siteUrl) }}" variant="outline" target="_blank" rel="noopener noreferrer">
                        <x-svg-icon name="facebook" />
                        Share on Facebook
                    </x-button>
                </div>
            </x-slot:actions>
        </x-confirmation-letter>
    </div>
</x-layouts.app>
