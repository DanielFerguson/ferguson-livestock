<?php

declare(strict_types=1);

use App\Jobs\SendMetaEvent;
use App\Models\Subscriber;
use App\Support\Turnstile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Js;

/**
 * @param  array<mixed>  $overrides
 * @return array<mixed>
 */
function waitlistForm(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Sam',
        'phone' => '0412 345 678',
        'postcode' => '3350',
        'sms_consent' => '1',
    ], $overrides);
}

it('adds the visitor to the wait list with a record of their consent', function (): void {
    $this->freezeSecond();

    $this->post('/waitlist', waitlistForm(), ['REMOTE_ADDR' => '203.0.113.7'])->assertRedirect('/thank-you');

    $subscriber = Subscriber::query()->sole();

    expect($subscriber->first_name)->toBe('Sam')
        ->and($subscriber->phone)->toBe('+61412345678')
        ->and($subscriber->postcode)->toBe('3350')
        ->and($subscriber->consented_at->equalTo(now()))->toBeTrue()
        ->and($subscriber->consent_source)->toBe('website wait list')
        ->and($subscriber->consent_wording)->toBe(Subscriber::CONSENT_WORDING)
        ->and($subscriber->consent_ip)->toBe('203.0.113.7')
        ->and($subscriber->unsubscribed_at)->toBeNull();
});

it('records the visitor’s address that Laravel Cloud’s edge forwards, not the proxy’s', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->post('/waitlist', waitlistForm(), ['X-Forwarded-For' => '203.0.113.7']);

    expect(Subscriber::query()->sole()->consent_ip)->toBe('203.0.113.7');
});

it('greets the visitor by name without putting it in the URL', function (): void {
    $this->followingRedirects()
        ->post('/waitlist', waitlistForm(['first_name' => '  Sam ']))
        ->assertOk()
        ->assertSee('Thanks for joining, Sam!');
});

it('still thanks visitors who arrive without signing up', function (): void {
    $this->get('/thank-you')->assertOk()->assertSee('Thanks for joining, friend!');
});

it('updates the existing entry when someone signs up again', function (): void {
    $this->post('/waitlist', waitlistForm(['postcode' => '3350']));
    $this->post('/waitlist', waitlistForm(['first_name' => 'Samantha', 'phone' => '+61 412 345 678', 'postcode' => '3351']));

    expect(Subscriber::query()->count())->toBe(1)
        ->and(Subscriber::query()->sole()->only('first_name', 'postcode'))->toBe(['first_name' => 'Samantha', 'postcode' => '3351']);
});

it('leaves an opt-out in place when the form names a number that opted out', function (): void {
    $optedOut = Subscriber::factory()->unsubscribed()->create(['phone' => '+61412345678', 'first_name' => 'Jo', 'postcode' => '3000', 'consent_ip' => '198.51.100.1']);
    $before = $optedOut->only('first_name', 'postcode', 'consented_at', 'consent_source', 'consent_ip', 'unsubscribed_at');

    $this->post('/waitlist', waitlistForm(), ['REMOTE_ADDR' => '203.0.113.7'])->assertRedirect('/thank-you');

    expect($optedOut->refresh()->isSubscribed())->toBeFalse()
        ->and($optedOut->only(array_keys($before)))->toEqual($before);
});

it('explains each problem next to the form', function (array $input, string $field, string $message): void {
    $this->from('/')
        ->post('/waitlist', waitlistForm($input))
        ->assertRedirect('/#waitlist')
        ->assertSessionHasErrorsIn('waitlist', [$field => $message]);

    expect(Subscriber::query()->count())->toBe(0);
})->with([
    'missing name' => [['first_name' => ''], 'first_name', 'Please tell us your first name.'],
    'landline' => [['phone' => '03 5344 1234'], 'phone', 'Please enter an Australian mobile number, like 0412 345 678. We send drop alerts by text.'],
    'bad postcode' => [['postcode' => '335'], 'postcode', 'Please enter your four-digit postcode.'],
    'no consent' => [['sms_consent' => null], 'sms_consent', 'Please tick the box so we can text you when the next drop opens.'],
]);

it('shows the errors and keeps what was typed when the page reloads', function (): void {
    $this->from('/')->post('/waitlist', waitlistForm(['phone' => '03 5344 1234']));

    $this->get('/')
        ->assertSee('Please enter an Australian mobile number', false)
        ->assertSee('value="Sam"', false)
        ->assertSee('aria-invalid="true"', false);
});

it('shows the same consent wording that it records', function (): void {
    $this->get('/')->assertSee(Subscriber::CONSENT_WORDING, false);
});

it('quietly ignores bots that fill in the hidden field', function (): void {
    $this->post('/waitlist', waitlistForm(['website' => 'https://spam.example']))->assertRedirect('/thank-you');

    expect(Subscriber::query()->count())->toBe(0);
});

it('slows down repeated sign-ups with a friendly message', function (): void {
    foreach (range(1, 5) as $attempt) {
        $this->post('/waitlist', waitlistForm(['phone' => "041234567{$attempt}"]));
    }

    $this->from('/')
        ->post('/waitlist', waitlistForm(['phone' => '0412345670']))
        ->assertRedirect('/#waitlist')
        ->assertSessionHasErrorsIn('waitlist', 'first_name');

    expect(Subscriber::query()->count())->toBe(5);
});

it('shows Cloudflare’s bot check on the form once Turnstile is set up', function (): void {
    fakeTurnstile(genuine: true);

    $this->get('/order')->assertOk()->assertSee('data-sitekey="test-site-key"', false);
});

it('turns away sign-ups that fail Cloudflare’s bot check', function (): void {
    fakeTurnstile(genuine: false);

    $this->from('/')
        ->post('/waitlist', waitlistForm(['cf-turnstile-response' => 'bot-token']))
        ->assertRedirect('/#waitlist')
        ->assertSessionHasErrorsIn('waitlist', ['cf-turnstile-response' => Turnstile::FAILED_MESSAGE]);

    expect(Subscriber::query()->count())->toBe(0);
});

it('turns away sign-ups without a bot check token once Turnstile is set up', function (): void {
    fakeTurnstile(genuine: true);

    $this->from('/')
        ->post('/waitlist', waitlistForm())
        ->assertSessionHasErrorsIn('waitlist', ['cf-turnstile-response' => Turnstile::FAILED_MESSAGE]);

    expect(Subscriber::query()->count())->toBe(0);
});

it('signs up visitors who pass Cloudflare’s bot check', function (): void {
    fakeTurnstile(genuine: true);

    $this->post('/waitlist', waitlistForm(['cf-turnstile-response' => 'person-token']))->assertRedirect('/thank-you');

    expect(Subscriber::query()->count())->toBe(1);
});

it('sends someone back to the form on this site, never to the page that linked them here', function (): void {
    $this->post('/waitlist', ['first_name' => ''], ['Referer' => 'https://evil.example/phish'])
        ->assertRedirect(url('/').'/#waitlist');
});

it('keeps people on this site when the wait-list limit is reached too', function (): void {
    foreach (range(1, 5) as $ignored) {
        $this->post('/waitlist', ['first_name' => ''], ['Referer' => 'https://evil.example/phish']);
    }

    $this->post('/waitlist', ['first_name' => ''], ['Referer' => 'https://evil.example/phish'])
        ->assertRedirect(url('/').'/#waitlist');
});

it('still returns people to the page they were on, on this site', function (): void {
    $this->post('/waitlist', ['first_name' => ''], ['Referer' => url('/order')])
        ->assertRedirect(url('/order').'#waitlist');
});

it('reports a sign-up to Meta from the server and the thank-you page, under one event ID', function (): void {
    $this->freezeSecond();
    enableMetaAds();
    Queue::fake([SendMetaEvent::class]);

    $thanks = $this->withUnencryptedCookies(['_fbp' => 'fb.1.1700000000000.111', '_fbc' => 'fb.1.1700000000000.click-abc'])
        ->withHeaders(['User-Agent' => 'Test Browser', 'Referer' => 'https://fergusonlivestock.com.au/beef-boxes?utm_source=meta'])
        ->followingRedirects()
        ->post('https://fergusonlivestock.com.au/waitlist', waitlistForm(), ['REMOTE_ADDR' => '203.0.113.7']);

    $event = queuedMetaEvent();
    expect($event)->toMatchArray([
        'event_name' => 'Lead',
        'event_time' => now()->getTimestamp(),
        'action_source' => 'website',
        'event_source_url' => 'https://fergusonlivestock.com.au/beef-boxes',
        'user_data' => [
            'ph' => hash('sha256', '61412345678'),
            'fn' => hash('sha256', 'sam'),
            'zp' => hash('sha256', '3350'),
            'country' => hash('sha256', 'au'),
            'client_ip_address' => '203.0.113.7',
            'client_user_agent' => 'Test Browser',
            'fbp' => 'fb.1.1700000000000.111',
            'fbc' => 'fb.1.1700000000000.click-abc',
        ],
    ]);
    $thanks->assertSee("fbq('track', 'Lead', ".Js::from((object) []).', '.Js::from(['eventID' => $event['event_id']]).');', false);
});

it('reports a sign-up to Meta only once, however often the thank-you page is reloaded', function (): void {
    enableMetaAds(conversionsApi: false);

    $this->followingRedirects()->post('/waitlist', waitlistForm())->assertSee("'Lead'", false);

    $this->get('/thank-you')->assertOk()->assertDontSee("'Lead'", false);
});

it('reports nothing to Meta for bots or for numbers that opted out', function (array $form): void {
    enableMetaAds();
    Queue::fake([SendMetaEvent::class]);
    Subscriber::factory()->unsubscribed()->create(['phone' => '+61499999999']);

    $this->followingRedirects()->post('/waitlist', waitlistForm($form))->assertDontSee("'Lead'", false);

    Queue::assertNotPushed(SendMetaEvent::class);
})->with([
    'bot' => [['website' => 'https://spam.example']],
    'opted out' => [['phone' => '0499 999 999']],
]);

it('reports nothing from the server without a Conversions API token', function (): void {
    enableMetaAds(conversionsApi: false);
    Queue::fake([SendMetaEvent::class]);

    $this->post('/waitlist', waitlistForm())->assertRedirect('/thank-you');

    Queue::assertNotPushed(SendMetaEvent::class);
});

it('keeps the ad campaign that brought a new subscriber to the site', function (): void {
    $this->withUnencryptedCookies(['fl_campaign' => 'source=meta&medium=paid&campaign=Spring+wait+list'])
        ->post('/waitlist', waitlistForm())
        ->assertRedirect('/thank-you');

    expect(Subscriber::query()->sole()->only('utm_source', 'utm_medium', 'utm_campaign'))
        ->toBe(['utm_source' => 'meta', 'utm_medium' => 'paid', 'utm_campaign' => 'Spring wait list']);
});

it('doesn’t credit an ad for someone already on the wait list', function (): void {
    $existing = Subscriber::factory()->create(['phone' => '+61412345678']);

    $this->withUnencryptedCookies(['fl_campaign' => 'source=meta&medium=paid&campaign=Spring+wait+list'])
        ->post('/waitlist', waitlistForm());

    expect($existing->refresh()->utm_source)->toBeNull();
});
