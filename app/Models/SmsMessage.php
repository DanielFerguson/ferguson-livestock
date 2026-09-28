<?php

namespace App\Models;

use App\Enums\SmsDirection;
use App\Enums\SmsStatus;
use App\Exceptions\SmsNotSent;
use App\Sms\SmsGateway;
use Carbon\CarbonImmutable;
use Database\Factories\SmsMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One text: sent to a subscriber as part of a broadcast, or received as a reply.
 *
 * @property int $id
 * @property int|null $subscriber_id
 * @property int|null $sms_broadcast_id
 * @property SmsDirection $direction
 * @property string $to
 * @property string $from
 * @property string $body
 * @property SmsStatus $status
 * @property string|null $twilio_sid
 * @property int|null $segments
 * @property int|null $error_code
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Subscriber|null $subscriber
 * @property-read SmsBroadcast|null $broadcast
 */
#[Fillable(['subscriber_id', 'sms_broadcast_id', 'direction', 'to', 'from', 'body', 'status', 'twilio_sid', 'segments', 'error_code', 'sent_at', 'delivered_at', 'read_at'])]
class SmsMessage extends Model
{
    /** @use HasFactory<SmsMessageFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Subscriber, $this>
     */
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    /**
     * @return BelongsTo<SmsBroadcast, $this>
     */
    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(SmsBroadcast::class, 'sms_broadcast_id');
    }

    /**
     * Take the broadcast's next waiting text, marking it as sending in the same transaction. Rows another worker
     * holds are skipped, so two workers never send the same text.
     */
    public static function claimNext(int $broadcastId): ?self
    {
        return DB::transaction(function () use ($broadcastId): ?self {
            $message = self::query()
                ->with('subscriber')
                ->where('sms_broadcast_id', $broadcastId)
                ->where('status', SmsStatus::Queued)
                ->orderBy('id')
                ->lock('for update skip locked')
                ->first();

            $message?->update(['status' => SmsStatus::Sending]);

            return $message;
        });
    }

    /**
     * Send a claimed text, unless its subscriber has opted out since the broadcast started. A failed text is never
     * retried: it might have reached Twilio, and texting someone twice is worse than missing them once.
     */
    public function sendWith(SmsGateway $sms): void
    {
        if ($this->subscriber?->isSubscribed() !== true) {
            $this->update(['status' => SmsStatus::Skipped]);

            return;
        }

        try {
            $sent = $sms->send($this->to, $this->body);
        } catch (SmsNotSent $exception) {
            $this->update(['status' => SmsStatus::Failed, 'error_code' => $exception->errorCode]);

            if ($exception->recipientOptedOut()) {
                $this->subscriber->optOut();
            }

            return;
        }

        $this->update(['status' => SmsStatus::Sent, 'twilio_sid' => $sent->sid, 'segments' => $sent->segments, 'sent_at' => now()]);
    }

    /**
     * Record Twilio's latest news about the text. Only moves forward, in one conditional update, because Twilio's
     * updates can arrive out of order and at the same time.
     */
    public function recordStatus(SmsStatus $status, ?int $errorCode = null): void
    {
        $earlier = array_filter(SmsStatus::cases(), fn (SmsStatus $current): bool => $status->isAfter($current));

        self::query()->whereKey($this->id)->whereIn('status', $earlier)->update(array_filter([
            'status' => $status,
            'error_code' => $errorCode,
            'delivered_at' => $status === SmsStatus::Delivered ? now() : null,
            'updated_at' => now(),
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => SmsDirection::class,
            'status' => SmsStatus::class,
            'segments' => 'integer',
            'error_code' => 'integer',
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
        ];
    }
}
