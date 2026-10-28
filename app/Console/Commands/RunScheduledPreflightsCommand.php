<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CheckDropBeforeItOpens;
use App\Jobs\RunDropPreflightWhenDue;
use App\Models\Drop;
use Illuminate\Console\Command;

class RunScheduledPreflightsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'drops:preflight';

    /**
     * @var string
     */
    protected $description = 'Check drops opening in the next 10 minutes, and time a check for those opening within the hour';

    public function handle(CheckDropBeforeItOpens $check): int
    {
        $upcoming = Drop::query()
            ->published()
            ->whereNull('preflight_ran_at')
            ->whereBetween('opens_at', [now(), now()->addMinutes(RunDropPreflightWhenDue::LOOKAHEAD_MINUTES)])
            ->get();

        foreach ($upcoming as $drop) {
            if ($drop->opens_at->isAfter(now()->addMinutes(CheckDropBeforeItOpens::MINUTES_BEFORE_OPENING))) {
                RunDropPreflightWhenDue::dispatch($drop->id);

                continue;
            }

            if ($check($drop)) {
                $this->info("Checked {$drop->name}.");
            }
        }

        return self::SUCCESS;
    }
}
