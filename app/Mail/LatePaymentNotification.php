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
 * Tells the farm about a customer who paid for an order after its items had gone back on sale and been bought by
 * someone else, and whether the payment was given back.
 */
class LatePaymentNotification extends Mailable implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 600];

    use SerializesModels;

    public function __construct(public Order $order, public bool $refunded)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $outcome = $this->refunded ? 'refunded' : 'needs you';

        return new Envelope(subject: "Paid too late, {$outcome}: order {$this->order->reference()}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.orders.late-payment', with: ['order' => $this->order->load('items.product'), 'refunded' => $this->refunded]);
    }
}
