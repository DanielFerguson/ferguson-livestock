<?php

declare(strict_types=1);

use App\Mcp\Support\ConfirmationTokens;
use Illuminate\Support\Facades\Cache;

beforeEach(fn (): ConfirmationTokens => $this->tokens = new ConfirmationTokens(Cache::store('array')));

it('accepts a token for the action and subject it was issued for', function (): void {
    $token = $this->tokens->issue('send-broadcast', 'broadcast:1', ['body' => 'Hi', 'recipients' => 3]);

    expect($this->tokens->consume($token, 'send-broadcast', 'broadcast:1', ['body' => 'Hi', 'recipients' => 3]))->toBeTrue();
});

it('works only once', function (): void {
    $token = $this->tokens->issue('send-broadcast', 'broadcast:1', ['body' => 'Hi']);

    $this->tokens->consume($token, 'send-broadcast', 'broadcast:1', ['body' => 'Hi']);

    expect($this->tokens->consume($token, 'send-broadcast', 'broadcast:1', ['body' => 'Hi']))->toBeFalse();
});

it('is voided when what was previewed has changed', function (): void {
    $token = $this->tokens->issue('send-broadcast', 'broadcast:1', ['body' => 'Hi']);

    expect($this->tokens->consume($token, 'send-broadcast', 'broadcast:1', ['body' => 'Hi there']))->toBeFalse();
});

it('is no good for another subject or another action', function (): void {
    $other = $this->tokens->issue('send-broadcast', 'broadcast:1', ['body' => 'Hi']);
    $wrongAction = $this->tokens->issue('send-broadcast', 'broadcast:1', ['body' => 'Hi']);

    expect($this->tokens->consume($other, 'send-broadcast', 'broadcast:2', ['body' => 'Hi']))->toBeFalse()
        ->and($this->tokens->consume($wrongAction, 'schedule-broadcast', 'broadcast:1', ['body' => 'Hi']))->toBeFalse();
});

it('is useless once it has expired', function (): void {
    $token = $this->tokens->issue('send-broadcast', 'broadcast:1', ['body' => 'Hi']);

    $this->travel(ConfirmationTokens::TTL_MINUTES + 1)->minutes();

    expect($this->tokens->consume($token, 'send-broadcast', 'broadcast:1', ['body' => 'Hi']))->toBeFalse();
});

it('rejects a token it never issued', function (): void {
    expect($this->tokens->consume('made-up', 'send-broadcast', 'broadcast:1', []))->toBeFalse();
});
