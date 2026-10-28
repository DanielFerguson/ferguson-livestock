<?php

declare(strict_types=1);

namespace App\Actions\Drops;

use App\Exceptions\CannotChangeDrop;
use App\Models\DropItem;

final class RemoveDropItem
{
    /**
     * @throws CannotChangeDrop
     */
    public function __invoke(DropItem $item): void
    {
        $reason = $item->whyItCantBeRemoved();

        if ($reason !== null) {
            throw new CannotChangeDrop("{$item->product->name} can’t be removed. {$reason}");
        }

        $item->delete();
    }
}
