<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the farm a customer moved their delivery to another night from their order page, in case the run is planned.
 */
class DeliveryNightChanged extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * A failed send is tried again a few times, like the other order emails.
     */
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 600];

    use SerializesModels;

    /**
     * @param  string  $previousNight  as customers read it: "Saturday night, 21 November"
     */
    public function __construct(public Order $order, public string $previousNight)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->order->reference()} moved to {$this->order->deliveryNight()}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.orders.night-changed');
    }
}
