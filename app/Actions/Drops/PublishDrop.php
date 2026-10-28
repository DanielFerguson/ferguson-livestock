<?php

declare(strict_types=1);

namespace App\Actions\Drops;

use App\Exceptions\CannotChangeDrop;
use App\Models\Drop;

/**
 * Puts a drop on sale: it goes live at its opening time and ends whichever drop was open before it.
 */
final readonly class PublishDrop
{
    public function __construct(private SaveDrop $saveDrop) {}

    /**
     * Why the drop can't be published yet, or null when nothing stands in the way.
     */
    public function blocker(Drop $drop): ?string
    {
        if ($drop->published_at !== null) {
            return 'This drop is already published.';
        }

        if ($drop->closes_at !== null && $drop->closes_at->isPast()) {
            return 'This drop’s closing time has already passed. Change it first.';
        }

        if ($drop->items->isEmpty()) {
            return 'Add at least one product before publishing.';
        }

        try {
            $this->saveDrop->assertNoClash($drop->opens_at, $drop->closes_at, $drop->id);
        } catch (CannotChangeDrop $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    /**
     * @throws CannotChangeDrop
     */
    public function __invoke(Drop $drop): Drop
    {
        $drop->loadMissing('items');

        $blocker = $this->blocker($drop);

        if ($blocker !== null) {
            throw new CannotChangeDrop($blocker);
        }

        $drop->update(['published_at' => now()]);

        return $drop->refresh();
    }
}
