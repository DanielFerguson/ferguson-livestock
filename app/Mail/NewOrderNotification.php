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
 * Tells the farm about each paid order.
 */
class NewOrderNotification extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * A burst of paid orders can hit the email provider's rate limit, so a failed send is tried again a few times.
     */
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 600];

    use SerializesModels;

    public function __construct(public Order $order)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "New order {$this->order->reference()}: {$this->order->customer_name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.orders.new-order', with: ['order' => $this->order->load('items.product')]);
    }
}
