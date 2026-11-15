<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Pages\Conversations;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\SmsMessage;
use App\Sms\ConversationList;
use App\Support\AustralianMobile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A push to every phone the admin has turned notifications on for: a new text, a customer arriving for pickup, or a
 * test. Tapping it opens $url in
 * the installed admin app, and the app icon's badge shows how many conversations need a look. It's queued, so
 * Twilio's webhook answers straight away; the test is sent with notifyNow().
 */
class AdminPush extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $title, public string $body, public string $url) {}

    /**
     * Someone has just texted the shop's number. The link is relative, so the tap stays inside the installed app.
     */
    public static function forText(SmsMessage $text): self
    {
        return new self(
            title: $text->subscriber->first_name ?? AustralianMobile::readable($text->contact_phone),
            body: Str::limit($text->body, 250),
            url: Conversations::getUrl(['phone' => $text->contact_phone], isAbsolute: false),
        );
    }

    /**
     * A pickup customer has tapped "We're here" on their order page. Tapping the push opens their order in the admin,
     * ready to mark it collected.
     */
    public static function forArrival(Order $order): self
    {
        return new self(
            title: ($order->customer_name ?? 'A customer').' is here',
            body: $order->reference(),
            url: OrderResource::getUrl('view', ['record' => $order], isAbsolute: false),
        );
    }

    /**
     * The "Send a test" button on Conversations.
     */
    public static function test(): self
    {
        return new self('Notifications are on', 'New texts will show up like this.', Conversations::getUrl(isAbsolute: false));
    }

    /**
     * Whether the site has both push keys. With only the public one, phones could sign up but every push to them would
     * be refused.
     */
    public static function isSetUp(): bool
    {
        $publicKey = config('webpush.vapid.public_key');
        $privateKey = config('webpush.vapid.private_key');

        return is_string($publicKey) && $publicKey !== '' && is_string($privateKey) && $privateKey !== '';
    }

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->data(['url' => $this->url, 'badge' => ConversationList::attentionCount()])
            // Wake the phone now, and keep trying for a day if it's off or out of signal.
            ->options(['TTL' => 86400, 'urgency' => 'high']);
    }
}
