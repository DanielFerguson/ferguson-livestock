<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;

/**
 * Staging and pull-request previews take free Stripe test-mode orders but send real email from the shop's domain.
 * Outside production (and local development and tests), every email goes to the shop's own inbox instead, so a
 * test order can't be used to send "your order is paid" to whatever address someone typed.
 */
class KeepNonProductionMailInHouse
{
    public function handle(MessageSending $event): void
    {
        if (app()->environment(['production', 'local', 'testing'])) {
            return;
        }

        $event->message->to(config()->string('shop.email'));
        $event->message->getHeaders()->remove('Cc');
        $event->message->getHeaders()->remove('Bcc');
    }
}
