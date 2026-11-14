<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Pages\Conversations;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the admin no phone gets pushes for new texts any more: Apple said the last one isn't signed up. Without it,
 * texts would just stop showing up on the phone, with nothing to say so.
 */
class PushesStopped extends Notification
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your phone has stopped getting notifications for new texts')
            ->greeting('Texts aren’t reaching your phone.')
            ->line('The site just tried to send your phone a notification, and Apple says it isn’t signed up any more. That happens when Ferguson Livestock is taken off the home screen, or on a new phone.')
            ->line('Until notifications are back on, check Conversations for new texts yourself.')
            ->action('Open Conversations', Conversations::getUrl())
            ->line('To turn them back on, open Ferguson Livestock from your phone’s home screen, go to Conversations and tap Turn on notifications.');
    }
}
