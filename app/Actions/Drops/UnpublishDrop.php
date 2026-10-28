<?php

declare(strict_types=1);

namespace App\Actions\Drops;

use App\Enums\DropStatus;
use App\Exceptions\CannotChangeDrop;
use App\Models\Drop;

/**
 * Takes a drop that hasn't opened yet back to a draft.
 */
final class UnpublishDrop
{
    /**
     * @throws CannotChangeDrop
     */
    public function __invoke(Drop $drop): Drop
    {
        if ($drop->status() !== DropStatus::Scheduled) {
            throw new CannotChangeDrop('Only a published drop that has not opened yet can go back to a draft. Use close-drop-now for one that is open.');
        }

        $drop->update(['published_at' => null]);

        return $drop->refresh();
    }
}
