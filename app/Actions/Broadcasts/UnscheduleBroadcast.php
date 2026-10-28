<?php

declare(strict_types=1);

namespace App\Actions\Broadcasts;

use App\Enums\BroadcastStatus;
use App\Exceptions\CannotChangeBroadcast;
use App\Models\SmsBroadcast;

final class UnscheduleBroadcast
{
    /**
     * @throws CannotChangeBroadcast
     */
    public function __invoke(SmsBroadcast $broadcast): SmsBroadcast
    {
        if ($broadcast->status() !== BroadcastStatus::Scheduled) {
            throw new CannotChangeBroadcast('Only a scheduled broadcast that has not started can be unscheduled.');
        }

        $broadcast->update(['scheduled_for' => null]);

        return $broadcast->refresh();
    }
}
