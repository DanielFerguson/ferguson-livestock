<?php

namespace Tests\Fakes;

use App\Exceptions\SmsNotSent;
use App\Sms\SentSms;
use App\Sms\SmsGateway;
use App\Sms\SmsText;
use Closure;

/**
 * An in-memory SMS provider: records each text, and refuses the numbers a test chooses.
 */
final class FakeSmsGateway implements SmsGateway
{
    /** @var list<array{to: string, body: string}> */
    public array $sent = [];

    /** @var array<string, int> */
    private array $refusals = [];

    private ?Closure $whileSending = null;

    /**
     * Put a fresh fake in the container in place of Twilio, and return it.
     */
    public static function swap(): self
    {
        app()->instance(SmsGateway::class, $fake = new self);

        return $fake;
    }

    public function refuse(string $to, int $errorCode): self
    {
        $this->refusals[$to] = $errorCode;

        return $this;
    }

    /**
     * Run a callback as each text is sent, e.g. to move the clock on.
     */
    public function whileSending(Closure $callback): self
    {
        $this->whileSending = $callback;

        return $this;
    }

    public function send(string $to, string $body): SentSms
    {
        if ($this->whileSending !== null) {
            ($this->whileSending)();
        }

        if (isset($this->refusals[$to])) {
            throw new SmsNotSent('Twilio refused the text.', $this->refusals[$to]);
        }

        $this->sent[] = ['to' => $to, 'body' => $body];

        return new SentSms('SM'.str_pad((string) count($this->sent), 32, '0', STR_PAD_LEFT), (new SmsText($body))->segments());
    }

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        return array_column($this->sent, 'to');
    }
}
