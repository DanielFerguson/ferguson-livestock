<?php

declare(strict_types=1);

use App\Models\SmsMessage;
use App\Models\Subscriber;
use App\Models\User;
use App\Notifications\AdminPush;

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
