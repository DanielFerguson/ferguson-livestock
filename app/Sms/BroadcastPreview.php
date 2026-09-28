<?php

namespace App\Sms;

use App\Models\SmsBroadcast;
use App\Support\Money;
use Illuminate\Support\Number;

/**
 * What a broadcast would take to send: its length in texts, who it reaches and roughly what it costs.
 */
final readonly class BroadcastPreview
{
    public function __construct(
        public SmsText $text,
        public int $recipients,
    ) {}

    public static function of(SmsBroadcast $broadcast): self
    {
        return new self($broadcast->text(), $broadcast->recipients()->count());
    }

    /**
     * In AUD cents, from Twilio's price per text.
     */
    public function estimatedCost(): int
    {
        return $this->recipients * $this->text->segments() * config()->integer('services.twilio.segment_cost');
    }

    /**
     * "162 characters, so 2 texts each"
     */
    public function length(): string
    {
        return Number::format($this->text->length()).' characters, so '.$this->texts().' each';
    }

    /**
     * "3 subscribers, about $0.48"
     */
    public function reach(): string
    {
        return $this->subscribers().', about '.Money::format($this->estimatedCost());
    }

    /**
     * "It goes to 2 subscribers as 1 text each, about $0.16."
     */
    public function confirmation(): string
    {
        return "It goes to {$this->subscribers()} as {$this->texts()} each, about ".Money::format($this->estimatedCost()).'.';
    }

    /**
     * Why the text holds fewer characters than usual, if it does.
     */
    public function alphabetWarning(): ?string
    {
        $characters = $this->text->charactersOutsideAlphabet();

        if ($characters === []) {
            return null;
        }

        $verb = count($characters) === 1 ? 'isn’t' : 'aren’t';

        return implode(' ', $characters)." {$verb} in the standard text alphabet, so each text holds 70 characters instead of 160.";
    }

    private function texts(): string
    {
        $segments = $this->text->segments();

        return $segments.' '.str('text')->plural($segments);
    }

    private function subscribers(): string
    {
        return Number::format($this->recipients).' '.str('subscriber')->plural($this->recipients);
    }
}
