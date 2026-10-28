<?php

declare(strict_types=1);

namespace App\Sms;

use App\Models\Drop;

/**
 * The text that tells the wait list when a drop opens.
 */
final class DropAnnouncement
{
    /**
     * "Our next beef drop opens Saturday 10 October at 9am. Order at fergusonlivestock.com.au/order"
     */
    public static function bodyFor(Drop $drop): string
    {
        $opensAt = $drop->opens_at->setTimezone(config()->string('shop.timezone'));
        $time = $opensAt->minute === 0 ? $opensAt->format('ga') : $opensAt->format('g:ia');
        $site = preg_replace('#^https?://(www\.)?#', '', rtrim(config()->string('shop.url'), '/'));

        return "Our next beef drop opens {$opensAt->format('l j F')} at {$time}. Order at {$site}".route('order', absolute: false);
    }
}
