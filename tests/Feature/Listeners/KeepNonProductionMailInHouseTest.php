<?php

use Illuminate\Mail\Message;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Send a plain email to a stranger (copying another), and return where the mailer actually sent it.
 *
 * @return array{to: list<string>, cc: list<string>}
 */
function sendToStranger(): array
{
    Mail::raw('Your order is paid.', fn (Message $message) => $message->to('stranger@example.test')->cc('another@example.test'));

    $transport = Mail::getSymfonyTransport();
    $sent = $transport instanceof ArrayTransport ? $transport->messages()->last() : null;
    $email = $sent instanceof SentMessage ? $sent->getOriginalMessage() : null;

    if (! $email instanceof Email) {
        return ['to' => [], 'cc' => []];
    }

    return [
        'to' => array_values(array_map(fn (Address $address): string => $address->getAddress(), $email->getTo())),
        'cc' => array_values(array_map(fn (Address $address): string => $address->getAddress(), $email->getCc())),
    ];
}

it('sends every email to the shop’s own inbox outside production', function (string $environment) {
    $this->app->detectEnvironment(fn () => $environment);

    expect(sendToStranger())->toBe(['to' => [config()->string('shop.email')], 'cc' => []]);
})->with(['staging', 'preview']);

it('sends email where it was addressed in production and locally', function (string $environment) {
    $this->app->detectEnvironment(fn () => $environment);

    expect(sendToStranger())->toBe(['to' => ['stranger@example.test'], 'cc' => ['another@example.test']]);
})->with(['production', 'local']);
