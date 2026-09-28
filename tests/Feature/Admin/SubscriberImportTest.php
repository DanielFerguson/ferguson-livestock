<?php

use App\Filament\Imports\SubscriberImporter;
use App\Models\Subscriber;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestableImport;

/**
 * The columns of a Klaviyo profile export, mapped to the importer's.
 */
function klaviyoImport(): TestableImport
{
    return SubscriberImporter::test([
        'phone' => 'Phone Number',
        'first_name' => 'First Name',
        'postcode' => 'Zip Code',
        'consented_at' => 'SMS Consent Timestamp',
        'sms_consent' => 'SMS Consent',
    ]);
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function klaviyoRow(array $overrides = []): array
{
    return array_merge([
        'Phone Number' => '+61412345678',
        'First Name' => 'Jane',
        'Zip Code' => '3363',
        'SMS Consent Timestamp' => '2025-11-02T09:15:00+00:00',
        'SMS Consent' => 'SUBSCRIBED',
    ], $overrides);
}

it('keeps the date each person agreed to texts in Klaviyo', function () {
    klaviyoImport()->import(klaviyoRow())->assertImported();

    $subscriber = Subscriber::sole();
    expect($subscriber->only('first_name', 'phone', 'postcode', 'consent_source'))->toBe([
        'first_name' => 'Jane',
        'phone' => '+61412345678',
        'postcode' => '3363',
        'consent_source' => 'klaviyo import',
    ])
        ->and($subscriber->consented_at->equalTo(CarbonImmutable::parse('2025-11-02 09:15', 'UTC')))->toBeTrue()
        ->and($subscriber->consent_wording)->toBe(Subscriber::KLAVIYO_CONSENT_WORDING)
        ->and($subscriber->isSubscribed())->toBeTrue();
});

it('reads mobile numbers however Klaviyo wrote them', function () {
    klaviyoImport()->import(klaviyoRow(['Phone Number' => '0412 345 678']))->assertImported();

    expect(Subscriber::sole()->phone)->toBe('+61412345678');
});

it('imports people without a name or postcode', function () {
    klaviyoImport()->import(klaviyoRow(['First Name' => '', 'Zip Code' => '']))->assertImported();

    expect(Subscriber::sole()->only('first_name', 'postcode'))->toBe(['first_name' => null, 'postcode' => null]);
});

it('skips anyone without SMS consent', function (string $column, string $value) {
    klaviyoImport()->import(klaviyoRow([$column => $value]))->assertSkipped();

    expect(Subscriber::count())->toBe(0);
})->with([
    'no consent date' => ['SMS Consent Timestamp', ''],
    'unsubscribed in Klaviyo' => ['SMS Consent', 'UNSUBSCRIBED'],
    'never subscribed' => ['SMS Consent', 'NEVER_SUBSCRIBED'],
]);

it('leaves people already on the list as they are, including anyone who opted out', function () {
    $optedOut = Subscriber::factory()->unsubscribed()->create(['phone' => '+61412345678', 'first_name' => 'J']);

    klaviyoImport()->import(klaviyoRow())->assertSkipped();

    expect($optedOut->refresh()->first_name)->toBe('J')
        ->and($optedOut->isSubscribed())->toBeFalse();
});

it('rejects numbers that aren’t Australian mobiles', function () {
    klaviyoImport()->import(klaviyoRow(['Phone Number' => '03 5342 0000']))->assertHasErrors(['phone']);

    expect(Subscriber::count())->toBe(0);
});
