@use('App\Support\StructuredData')

@php
    $title = 'Privacy | Ferguson Livestock';
    $description = 'How Ferguson Livestock collects, uses and protects information provided through the wait list and ordering.';
@endphp

<x-layouts.app :title="$title" :description="$description" :structured-data="StructuredData::webPage('/privacy', $title, $description)">
    <div class="bg-cream py-16 lg:py-24">
        <article class="legal-content mx-auto max-w-3xl px-6 text-gray-700">
            <p class="eyebrow text-sage">Last updated 28 September 2026</p>
            <h1>Privacy</h1>
            <p>Ferguson Livestock is a family-run business in Snake Valley, Victoria. This page explains in plain language what information we collect through this website and how we use it.</p>

            <h2>Information we collect</h2>
            <p>When you join the beef-drop wait list, we collect your first name, mobile number and postcode, and record when and how you agreed to receive our messages. When you place an order, we keep the contact, delivery and order details needed to complete it, and Stripe collects your payment information. We do not receive or store your complete card details.</p>

            <h2>How we use it</h2>
            <p>We use wait-list details to plan deliveries around our area and to send messages about Ferguson Livestock beef drops. We use order information to fulfil purchases, arrange delivery or pickup, send order confirmations, respond to questions and keep required business records.</p>

            <h2>Service providers</h2>
            <p>We keep wait-list and order details in our own system. We use service providers including a text-message provider to send wait-list messages, Stripe for checkout, Resend for order emails, and Laravel Cloud (which runs on Amazon Web Services and Cloudflare) to host this website and store that information. These providers handle information under their own privacy and security terms and may process data outside Australia.</p>

            <h2>Marketing consent and opting out</h2>
            <p>Joining the wait list means you consent to receive Ferguson Livestock messages about beef drops. Messages will identify Ferguson Livestock and include a way to opt out. You can also ask us to stop at any time by replying to a message or contacting us. We will action unsubscribe requests promptly.</p>

            <h2>Access, correction and questions</h2>
            <p>To ask what information we hold about you, correct it, request deletion where appropriate, or raise a privacy concern, contact us at <a href="mailto:{{ config('shop.email') }}">{{ config('shop.email') }}</a> or <a href="tel:{{ config('shop.phone.international') }}">{{ config('shop.phone.display') }}</a>.</p>

            <p>This statement is maintained as our tools and practices change. Australian businesses using commercial SMS must have consent, identify the sender and provide a working unsubscribe method; further guidance is available from the <a href="https://www.acma.gov.au/avoid-sending-spam">Australian Communications and Media Authority</a>.</p>
        </article>
    </div>
</x-layouts.app>
