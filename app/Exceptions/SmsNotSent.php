<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The SMS provider refused a text or couldn't be reached.
 */
class SmsNotSent extends RuntimeException
{
    /**
     * Twilio's code when someone replied STOP to Twilio, which then refuses to text them.
     */
    public const int RECIPIENT_OPTED_OUT = 21610;

    public function __construct(string $message, public readonly ?int $errorCode = null)
    {
        parent::__construct($message);
    }

    public function recipientOptedOut(): bool
    {
        return $this->errorCode === self::RECIPIENT_OPTED_OUT;
    }
}
