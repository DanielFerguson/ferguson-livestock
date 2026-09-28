<?php

use App\Sms\OptOutKeywords;

it('recognises replies asking to stop', function (string $reply) {
    expect(OptOutKeywords::matches($reply))->toBeTrue();
})->with([
    'STOP',
    'stop.',
    'Stop please',
    'STOP!!!',
    'Unsubscribe',
    'Cancel',
    'opt out',
    'Please remove me',
    'No more texts thanks',
    'Don’t text me',
    'take me off your list',
]);

it('leaves other replies alone', function (string $reply) {
    expect(OptOutKeywords::matches($reply))->toBeFalse();
})->with([
    'Can’t wait for the next drop!',
    'When does the drop open?',
    'Cancel my order please',
    'Please don’t stop sending these',
    'I will stop by the farm to pick up my box on Saturday morning',
]);
