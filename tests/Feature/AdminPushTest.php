<?php

declare(strict_types=1);

use App\Models\SmsMessage;
use App\Models\Subscriber;
use App\Models\User;
use App\Notifications\AdminPush;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Event;
use NotificationChannels\WebPush\Events\NotificationFailed;

it('shows who texted and what they said, opens their conversation inside the app, and counts what needs a look', function (): void {
    $jess = Subscriber::factory()->create(['first_name' => 'Jess', 'phone' => '+61411222333']);
    $text = SmsMessage::factory()->for($jess)->reply('Can I swap to Thursday pickup?')->create();
    SmsMessage::factory()->reply('Someone else')->create(['subscriber_id' => null, 'from' => '+61499999999']);

    expect(AdminPush::forText($text)->toWebPush(new User)->toArray())->toMatchArray([
        'title' => 'Jess',
        'body' => 'Can I swap to Thursday pickup?',
        'data' => ['url' => '/admin/conversations?phone=%2B61411222333', 'badge' => 2],
    ]);
});

it('names someone who isn’t on the wait list by their number', function (): void {
    $text = SmsMessage::factory()->reply('Do you deliver to Creswick?')->create(['subscriber_id' => null, 'from' => '+61412000111']);

    expect(AdminPush::forText($text)->toWebPush(new User)->toArray())->toMatchArray(['title' => '0412 000 111']);
});

it('cuts a long text short enough to fit in a push', function (): void {
    $text = SmsMessage::factory()->reply(str_repeat('🐄', 400))->create(['subscriber_id' => null, 'from' => '+61412000111']);

    expect(strlen(json_encode(AdminPush::forText($text)->toWebPush(new User)->toArray(), JSON_THROW_ON_ERROR)))->toBeLessThan(4000);
});

it('signs every push afresh, so a queue worker that has run for over 12 hours doesn’t send an expired signature', function (): void {
    fakeApplePush(500, 201);
    $admin = User::factory()->create();
    $admin->updatePushSubscription('https://web.push.apple.com/phone', 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'tBHItJI5svbpez7KI4CCXg', 'aes128gcm');
    // A send cut short is what leaves a signature behind for the next push to reuse.
    Event::listen(NotificationFailed::class, fn (): never => throw new RuntimeException('Cut short'));
    rescue(fn () => $admin->notifyNow(AdminPush::test()), report: false);

    $admin->notifyNow(AdminPush::test());

    [$first, $second] = app(HttpFactory::class)->recorded()->map(fn (array $exchange): string => $exchange[0]->toPsrRequest()->getHeaderLine('Authorization'))->all();
    expect($second)->not->toBe($first);
});
