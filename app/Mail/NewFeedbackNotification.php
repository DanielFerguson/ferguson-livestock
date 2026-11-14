<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\OrderFeedback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the farm what a customer thought of their order, each time they leave or change it.
 */
class NewFeedbackNotification extends Mailable implements ShouldQueue
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
     * @param  bool  $edited  they changed feedback they'd left before
     */
    public function __construct(public OrderFeedback $feedback, public bool $edited)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $order = $this->feedback->loadMissing('order')->order;

        return new Envelope(subject: "{$this->feedback->stars()} from {$order->customer_name} for {$order->reference()}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.orders.new-feedback', with: ['order' => $this->feedback->loadMissing('order')->order]);
    }
}
