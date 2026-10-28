<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Text people typed, made safe to put in a Markdown email.
 *
 * Blade escapes HTML, but the email is then rendered as Markdown, so a buyer's name like
 * "[Track your order](https://…)" would become a real link (and "![](…)" a tracking image). Markdown's punctuation
 * is written as numeric character references, which Markdown never reads as syntax and the plain-text version of
 * the email decodes back to the characters typed. Backslash escapes would show up in that plain-text version.
 */
final class MarkdownText
{
    public static function escape(?string $text): HtmlString
    {
        // `#` is left out: e() writes apostrophes as &#039;, and a heading only starts at the beginning of a line.
        return new HtmlString((string) preg_replace_callback(
            '/[\\\\`*_{}\[\]()+\-.!|~]/',
            fn (array $match): string => '&#'.ord($match[0]).';',
            e($text ?? ''),
        ));
    }
}
