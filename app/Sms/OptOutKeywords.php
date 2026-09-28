<?php

namespace App\Sms;

/**
 * Whether a reply asks to stop receiving texts.
 *
 * Twilio only blocks a number when the whole reply is one of its keywords, like STOP. People also write
 * "stop please" or "remove me", so short replies containing those phrases count too. This errs toward opting
 * people out: texting someone who asked to stop breaks the Spam Act.
 */
final class OptOutKeywords
{
    /**
     * Twilio's opt-out keywords, which count only as the whole reply.
     */
    private const array KEYWORDS = ['stop', 'stopall', 'stop all', 'unsubscribe', 'cancel', 'end', 'quit', 'optout', 'opt out', 'revoke'];

    /**
     * Phrases that ask to opt out anywhere in a short reply.
     */
    private const array PHRASES = ['stop', 'unsubscribe', 'remove me', 'no more', 'opt out', 'optout', 'take me off', 'dont text', 'do not text'];

    private const array NOT_STOPPING = ['dont stop', 'do not stop', 'never stop', 'stop by'];

    /**
     * Longer replies are conversation, not an opt-out.
     */
    private const int SHORT_REPLY_WORDS = 6;

    public static function matches(string $reply): bool
    {
        $words = self::words($reply);

        if (in_array($words, self::KEYWORDS, true)) {
            return true;
        }

        if (substr_count($words, ' ') >= self::SHORT_REPLY_WORDS || self::containsAny($words, self::NOT_STOPPING)) {
            return false;
        }

        return self::containsAny($words, self::PHRASES);
    }

    /**
     * The reply in lower case with punctuation removed: "Don’t text me!" becomes "dont text me".
     */
    private static function words(string $reply): string
    {
        $withoutApostrophes = str_replace(["'", '’'], '', mb_strtolower($reply));

        return trim((string) preg_replace('/[^a-z]+/', ' ', $withoutApostrophes));
    }

    /**
     * @param  list<string>  $phrases
     */
    private static function containsAny(string $words, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (preg_match('/\b'.preg_quote($phrase, '/').'\b/', $words) === 1) {
                return true;
            }
        }

        return false;
    }
}
