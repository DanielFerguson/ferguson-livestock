<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A reply wasn't sent because it isn't allowed or isn't worth sending. The message is written for the admin.
 */
class CannotReply extends RuntimeException
{
    public static function optedOut(): self
    {
        return new self('They’ve opted out of texts, so you can’t text them.');
    }

    public static function neverTextedIn(): self
    {
        return new self('They haven’t texted you, so you can only reach them with a broadcast.');
    }

    public static function empty(): self
    {
        return new self('Write something to send.');
    }

    public static function tooLong(int $limit): self
    {
        return new self("That’s too long: keep it to {$limit} characters.");
    }
}
