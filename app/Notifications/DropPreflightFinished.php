<?php

namespace App\Notifications;

use App\Filament\Resources\Drops\DropResource;
use App\Models\Drop;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the admin, by email and in the admin, whether a drop is ready to open.
 */
class DropPreflightFinished extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{passed: bool, problems: list<string>, checked_at: string}  $report
     */
    public function __construct(public Drop $drop, public array $report) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof AnonymousNotifiable ? ['mail'] : ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $opens = $this->drop->opens_at->setTimezone(config()->string('shop.timezone'))->format('g:ia l j F');

        $mail = (new MailMessage)
            ->subject($this->report['passed'] ? "Ready to open: {$this->drop->name}" : "Fix before opening: {$this->drop->name}")
            ->greeting($this->report['passed'] ? 'All set.' : 'Some things need fixing.')
            ->line("{$this->drop->name} opens at {$opens}.");

        if ($this->report['passed']) {
            $mail->line('Every price, the stock, the delivery fee and delivery days, and the Stripe webhook all check out.');
        } else {
            $mail->line('The pre-flight check found:');

            foreach ($this->report['problems'] as $problem) {
                $mail->line('• '.$problem);
            }
        }

        return $mail
            ->line('Reminder: raise the minimum number of app replicas in Laravel Cloud before the drop opens.')
            ->action('Open the drop', DropResource::getUrl('edit', ['record' => $this->drop]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title($this->report['passed'] ? "{$this->drop->name} is ready to open" : "{$this->drop->name} needs fixing before it opens")
            ->body($this->report['passed'] ? null : implode("\n", $this->report['problems']))
            ->actions([
                Action::make('open')->label('Open the drop')->url(DropResource::getUrl('edit', ['record' => $this->drop])),
            ]);

        return ($this->report['passed'] ? $notification->success() : $notification->danger())->getDatabaseMessage();
    }
}
