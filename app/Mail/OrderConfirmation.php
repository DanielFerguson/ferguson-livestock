<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the customer once their payment has gone through.
 */
class OrderConfirmation extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Order $order)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address(config()->string('shop.email'), config()->string('shop.name'))],
            subject: "Your Ferguson Livestock order {$this->order->reference()}",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.orders.confirmation', with: ['order' => $this->order->load('items.product')]);
    }
}
