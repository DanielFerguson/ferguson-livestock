<?php

declare(strict_types=1);

namespace App\Actions\Drops;

use App\Models\Drop;

/**
 * Switches the early-access link off. Orders already placed through it stand.
 */
final class DisableEarlyAccess
{
    public function __invoke(Drop $drop): Drop
    {
        if ($drop->early_access_token !== null) {
            $drop->stopEarlyAccess();
        }

        return $drop->refresh();
    }
}
