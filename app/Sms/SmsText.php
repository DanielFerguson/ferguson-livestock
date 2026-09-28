<?php

namespace App\Sms;

/**
 * A text message measured the way phone networks bill it.
 *
 * Texts that only use the GSM alphabet fit 160 characters in one text (153 per text once split). One character
 * outside it, like an emoji, switches the whole message to UCS-2: 70 characters, then 67 per text.
 */
final readonly class SmsText
{
    /**
     * The Spam Act requires every commercial text to say who sent it.
     */
    public const string SENDER = 'Ferguson Livestock: ';

    /**
     * ...and to include a working way to unsubscribe.
     */
    public const string OPT_OUT = "\nReply STOP to opt out";

    private const string ALPHABET = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    /**
     * Characters in the GSM alphabet's extension table, which each take two places.
     */
    private const string EXTENSION = "^{}\\[~]|€\f";

    private const array PLAIN_PUNCTUATION = [
        '‘' => "'", '’' => "'", '‚' => "'", '′' => "'",
        '“' => '"', '”' => '"', '„' => '"', '″' => '"',
        '–' => '-', '—' => '-', '…' => '...', "\u{00A0}" => ' ', "\r\n" => "\n",
    ];

    public function __construct(public string $text) {}

    /**
     * The message as subscribers receive it: the sender's name first and the opt-out line last.
     */
    public static function forBroadcast(string $body): self
    {
        return new self(self::SENDER.self::tidy($body).self::OPT_OUT);
    }

    /**
     * Swap the curly quotes, dashes and ellipses that phones and word processors insert for plain ones, so they
     * don't halve the characters a text can hold.
     */
    public static function tidy(string $body): string
    {
        return trim(strtr($body, self::PLAIN_PUNCTUATION));
    }

    public function isGsm(): bool
    {
        return $this->charactersOutsideAlphabet() === [];
    }

    /**
     * @return list<string>
     */
    public function charactersOutsideAlphabet(): array
    {
        return array_values(array_unique(array_filter(
            mb_str_split($this->text),
            fn (string $character): bool => ! str_contains(self::ALPHABET.self::EXTENSION, $character),
        )));
    }

    /**
     * The length phone networks count: extension characters count twice in the GSM alphabet, and characters
     * outside the basic plane, like emoji, count twice in UCS-2.
     */
    public function length(): int
    {
        if (! $this->isGsm()) {
            return intdiv(strlen((string) mb_convert_encoding($this->text, 'UTF-16BE', 'UTF-8')), 2);
        }

        return array_sum(array_map(
            fn (string $character): int => str_contains(self::EXTENSION, $character) ? 2 : 1,
            mb_str_split($this->text),
        ));
    }

    /**
     * How many texts each person is sent, and billed for.
     */
    public function segments(): int
    {
        [$single, $perPart] = $this->isGsm() ? [160, 153] : [70, 67];
        $length = $this->length();

        return $length <= $single ? 1 : (int) ceil($length / $perPart);
    }
}
