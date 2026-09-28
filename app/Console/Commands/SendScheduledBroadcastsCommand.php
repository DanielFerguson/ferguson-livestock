<?php

namespace App\Console\Commands;

use App\Actions\StartBroadcast;
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
    protected $description = 'Start sending broadcasts whose scheduled time has come';

    public function handle(StartBroadcast $start): int
    {
        foreach (SmsBroadcast::query()->due()->get() as $broadcast) {
            $queued = $start($broadcast);
            $this->info("Queued {$queued} texts for broadcast {$broadcast->id}.");
        }

        return self::SUCCESS;
    }
}
