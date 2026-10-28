<?php

declare(strict_types=1);

namespace App\Actions\Drops;

use App\Exceptions\CannotChangeDrop;
use App\Models\Drop;

final class DeleteDrop
{
    /**
     * @throws CannotChangeDrop
     */
    public function __invoke(Drop $drop): void
    {
        $drop->loadMissing('items');

        $reason = $drop->whyItCantBeDeleted();

        if ($reason !== null) {
            throw new CannotChangeDrop($reason);
        }

        $drop->delete();
    }
}
