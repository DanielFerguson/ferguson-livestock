<?php

namespace App\Actions;

use App\Enums\SmsDirection;
use App\Enums\SmsStatus;
use App\Jobs\SendBroadcastMessages;
use App\Models\SmsBroadcast;
use Illuminate\Support\Facades\DB;

/**
 * Start sending a broadcast: queue one text per recipient, then hand them to the queue.
 *
 * Starting is a compare-and-set on `started_at`, and each recipient's row is unique per broadcast, so starting
 * twice, from two admin tabs or the scheduler and a click, never texts anyone twice.
 */
final class StartBroadcast
{
    /**
     * @return int|null how many texts were queued, or null when the broadcast had already started
     */
    public function __invoke(SmsBroadcast $broadcast): ?int
    {
        $queued = DB::transaction(function () use ($broadcast): ?int {
            $claimed = SmsBroadcast::query()
                ->whereKey($broadcast->id)
                ->whereNull('started_at')
                ->update(['started_at' => now(), 'updated_at' => now()]);

            if ($claimed === 0) {
                return null;
            }

            $recipients = $broadcast->recipients()->toBase()->selectRaw(
                'id, ?::bigint, ?, phone, ?, ?, ?, ?::timestamp, ?::timestamp',
                [$broadcast->id, SmsDirection::Outbound->value, config()->string('services.twilio.from'), $broadcast->text()->text, SmsStatus::Queued->value, now(), now()],
            );

            return DB::table('sms_messages')->insertOrIgnoreUsing(
                ['subscriber_id', 'sms_broadcast_id', 'direction', 'to', 'from', 'body', 'status', 'created_at', 'updated_at'],
                $recipients,
            );
        });

        if ($queued !== null) {
            SendBroadcastMessages::dispatch($broadcast->id);
        }

        $broadcast->refresh();

        return $queued;
    }
}
