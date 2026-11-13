<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SmsStatus;
use App\Exceptions\SmsNotSent;
use App\Jobs\Concerns\WaitsUntilDue;
use App\Models\SmsMessage;
use App\Sms\QuietHours;
use App\Sms\SmsGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Sends a saved order confirmation text (see OrderConfirmationTexts) once it's due: straight away, or at 8am if it
 * was saved in quiet hours, unless the owner chose to send it now.
 *
 * The text is marked as sending before it goes, so a retry, the morning's copy of this job or the owner pressing
 * "Send now" never sends it twice. The sync queue never sends it (see WaitsUntilDue), so a machine without a worker
 * can't text anyone.
 */
class SendOrderConfirmationText implements ShouldQueue
{
    use Queueable, WaitsUntilDue;

    /**
     * An unexpected error, like the database dropping out, is retried: a text already claimed isn't sent again.
     */
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 120];

    /**
     * @param  bool  $evenInQuietHours  the owner chose to send now, though it's between 8pm and 8am
     */
    public function __construct(public int $messageId, public bool $evenInQuietHours = false) {}

    public function handle(SmsGateway $sms): void
    {
        if ($this->waitingUntil($this->evenInQuietHours ? now() : QuietHours::nextSendingTime(now()))) {
            return;
        }

        $message = DB::transaction(function (): ?SmsMessage {
            $message = SmsMessage::query()
                ->with('subscriber')
                ->whereKey($this->messageId)
                ->where('is_order_confirmation', true)
                ->where('status', SmsStatus::Queued)
                ->lock('for update skip locked')
                ->first();

            $message?->update(['status' => SmsStatus::Sending]);

            return $message;
        });

        if (! $message instanceof SmsMessage) {
            return;
        }

        // They may have texted STOP while it waited.
        if ($message->subscriber !== null && ! $message->subscriber->isSubscribed()) {
            $message->update(['status' => SmsStatus::Skipped]);

            return;
        }

        try {
            $sent = $sms->send($message->to, $message->body);
        } catch (SmsNotSent $exception) {
            $message->update(['status' => SmsStatus::Failed, 'error_code' => $exception->errorCode]);

            if ($exception->recipientOptedOut()) {
                $message->subscriber?->optOut();
            }

            return;
        }

        $message->update(['status' => SmsStatus::Sent, 'twilio_sid' => $sent->sid, 'segments' => $sent->segments, 'sent_at' => now()]);
    }

    /**
     * When the retries run out, show the text as failed rather than leaving it waiting for ever.
     */
    public function failed(): void
    {
        SmsMessage::query()->whereKey($this->messageId)->whereIn('status', [SmsStatus::Queued, SmsStatus::Sending])->update(['status' => SmsStatus::Failed]);
    }
}
