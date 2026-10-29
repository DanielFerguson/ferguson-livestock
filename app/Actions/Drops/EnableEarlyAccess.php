<?php

declare(strict_types=1);

namespace App\Actions\Drops;

use App\Exceptions\CannotChangeDrop;
use App\Models\Drop;

/**
 * Lets people with the drop's secret link order before it is public. Asking again keeps the same link, unless
 * a new one is wanted, which stops the old one working.
 */
final class EnableEarlyAccess
{
    /**
     * @throws CannotChangeDrop
     */
    public function __invoke(Drop $drop, bool $newLink = false): Drop
    {
        if ($drop->hasEnded()) {
            throw new CannotChangeDrop('This drop has closed, so it can’t take early orders.');
        }

        if ($drop->items->isEmpty()) {
            throw new CannotChangeDrop('Add products to this drop before turning on early access.');
        }

        if ($drop->early_access_token === null || $newLink) {
            $drop->startEarlyAccess();
        }

        return $drop->refresh();
    }
}
