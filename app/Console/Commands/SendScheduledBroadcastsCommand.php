<?php

namespace App\Console\Commands;

use App\Actions\StartBroadcast;
use App\Jobs\StartBroadcastWhenDue;
use App\Models\SmsBroadcast;
use Illuminate\Console\Command;

class SendScheduledBroadcastsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sms:send-scheduled';

    /**
     * @var string
     */
    protected $description = 'Start broadcasts whose scheduled time has come, and time the start of those due within the hour';

    public function handle(StartBroadcast $start): int
    {
        foreach (SmsBroadcast::query()->due()->get() as $broadcast) {
            $queued = $start($broadcast);
            $this->info("Queued {$queued} texts for broadcast {$broadcast->id}.");
        }

        $upcoming = SmsBroadcast::query()
            ->whereNull('started_at')
            ->whereBetween('scheduled_for', [now(), now()->addMinutes(StartBroadcastWhenDue::LOOKAHEAD_MINUTES)])
            ->get();

        foreach ($upcoming as $broadcast) {
            StartBroadcastWhenDue::dispatch($broadcast->id);
        }

        return self::SUCCESS;
    }
}
