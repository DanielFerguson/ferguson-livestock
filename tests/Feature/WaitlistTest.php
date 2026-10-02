<?php

use App\Models\Subscriber;
use App\Support\Turnstile;

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

it('adds the visitor to the wait list with a record of their consent', function () {
    $this->freezeSecond();

    $this->post('/waitlist', waitlistForm(), ['REMOTE_ADDR' => '203.0.113.7'])->assertRedirect('/thank-you');

    $subscriber = Subscriber::sole();

    expect($subscriber->first_name)->toBe('Sam')
        ->and($subscriber->phone)->toBe('+61412345678')
        ->and($subscriber->postcode)->toBe('3350')
        ->and($subscriber->consented_at->equalTo(now()))->toBeTrue()
        ->and($subscriber->consent_source)->toBe('website wait list')
        ->and($subscriber->consent_wording)->toBe(Subscriber::CONSENT_WORDING)
        ->and($subscriber->consent_ip)->toBe('203.0.113.7')
        ->and($subscriber->unsubscribed_at)->toBeNull();
});

it('greets the visitor by name without putting it in the URL', function () {
    $this->followingRedirects()
        ->post('/waitlist', waitlistForm(['first_name' => '  Sam ']))
        ->assertOk()
        ->assertSee('Thanks for joining, Sam!');
});

it('still thanks visitors who arrive without signing up', function () {
    $this->get('/thank-you')->assertOk()->assertSee('Thanks for joining, friend!');
});

it('updates the existing entry when someone signs up again', function () {
    $this->post('/waitlist', waitlistForm(['postcode' => '3350']));
    $this->post('/waitlist', waitlistForm(['first_name' => 'Samantha', 'phone' => '+61 412 345 678', 'postcode' => '3351']));

    expect(Subscriber::count())->toBe(1)
        ->and(Subscriber::sole()->only('first_name', 'postcode'))->toBe(['first_name' => 'Samantha', 'postcode' => '3351']);
});

it('resubscribes someone who had opted out, with fresh consent', function () {
    $optedOut = Subscriber::factory()->unsubscribed()->create(['phone' => '+61412345678', 'consented_at' => now()->subYear()]);

    $this->freezeSecond();
    $this->post('/waitlist', waitlistForm());

    $optedOut->refresh();

    expect($optedOut->unsubscribed_at)->toBeNull()
        ->and($optedOut->consented_at->equalTo(now()))->toBeTrue();
});

it('explains each problem next to the form', function (array $input, string $field, string $message) {
    $this->from('/')
        ->post('/waitlist', waitlistForm($input))
        ->assertRedirect('/#waitlist')
        ->assertSessionHasErrorsIn('waitlist', [$field => $message]);

    expect(Subscriber::count())->toBe(0);
})->with([
    'missing name' => [['first_name' => ''], 'first_name', 'Please tell us your first name.'],
    'landline' => [['phone' => '03 5344 1234'], 'phone', 'Please enter an Australian mobile number, like 0412 345 678. We send drop alerts by text.'],
    'bad postcode' => [['postcode' => '335'], 'postcode', 'Please enter your four-digit postcode.'],
    'no consent' => [['sms_consent' => null], 'sms_consent', 'Please tick the box so we can text you when the next drop opens.'],
]);

it('shows the errors and keeps what was typed when the page reloads', function () {
    $this->from('/')->post('/waitlist', waitlistForm(['phone' => '03 5344 1234']));

    $this->get('/')
        ->assertSee('Please enter an Australian mobile number', false)
        ->assertSee('value="Sam"', false)
        ->assertSee('aria-invalid="true"', false);
});

it('shows the same consent wording that it records', function () {
    $this->get('/')->assertSee(Subscriber::CONSENT_WORDING, false);
});

it('quietly ignores bots that fill in the hidden field', function () {
    $this->post('/waitlist', waitlistForm(['website' => 'https://spam.example']))->assertRedirect('/thank-you');

    expect(Subscriber::count())->toBe(0);
});

it('slows down repeated sign-ups with a friendly message', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post('/waitlist', waitlistForm(['phone' => "041234567{$attempt}"]));
    }

    $this->from('/')
        ->post('/waitlist', waitlistForm(['phone' => '0412345670']))
        ->assertRedirect('/#waitlist')
        ->assertSessionHasErrorsIn('waitlist', 'first_name');

    expect(Subscriber::count())->toBe(5);
});

it('shows Cloudflare’s bot check on the form once Turnstile is set up', function () {
    fakeTurnstile(genuine: true);

    $this->get('/order')->assertOk()->assertSee('data-sitekey="test-site-key"', false);
});

it('turns away sign-ups that fail Cloudflare’s bot check', function () {
    fakeTurnstile(genuine: false);

    $this->from('/')
        ->post('/waitlist', waitlistForm(['cf-turnstile-response' => 'bot-token']))
        ->assertRedirect('/#waitlist')
        ->assertSessionHasErrorsIn('waitlist', ['cf-turnstile-response' => Turnstile::FAILED_MESSAGE]);

    expect(Subscriber::count())->toBe(0);
});

it('turns away sign-ups without a bot check token once Turnstile is set up', function () {
    fakeTurnstile(genuine: true);

    $this->from('/')
        ->post('/waitlist', waitlistForm())
        ->assertSessionHasErrorsIn('waitlist', ['cf-turnstile-response' => Turnstile::FAILED_MESSAGE]);

    expect(Subscriber::count())->toBe(0);
});

it('signs up visitors who pass Cloudflare’s bot check', function () {
    fakeTurnstile(genuine: true);

    $this->post('/waitlist', waitlistForm(['cf-turnstile-response' => 'person-token']))->assertRedirect('/thank-you');

    expect(Subscriber::count())->toBe(1);
});
