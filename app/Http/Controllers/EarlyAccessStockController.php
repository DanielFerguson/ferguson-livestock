<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DropStatus;
use App\Models\Drop;
use App\Stock\DropSnapshot;
use Illuminate\Http\JsonResponse;

/**
 * What's left of an early-access drop, in the shape of /api/drop, so the live stock script can poll it in place of
 * the public feed on the early-access page. It is
 * private to the link, so nothing may cache it.
 */
class EarlyAccessStockController extends Controller
{
    public function __invoke(string $token): JsonResponse
    {
        $drop = Drop::query()->where('early_access_token', $token)->first();

        abort_unless($drop instanceof Drop, 404);

        $stock = DropSnapshot::stockOf($drop);

        $status = $drop->status();

        return response()
            ->json([
                'drop' => [
                    'id' => $drop->id,
                    'name' => $drop->name,
                    // An unopened drop reads as live here: this feed only goes to people who can order from it.
                    'state' => $drop->acceptsEarlyOrders() ? ($status->isOpen() ? $status->value : DropStatus::Live->value) : DropStatus::Closed->value,
                    'opens_at' => $drop->opens_at->toIso8601ZuluString(),
                    'closes_at' => $drop->effectiveClosesAt()?->toIso8601ZuluString(),
                ],
                'announced' => null,
                'server_time' => now()->toIso8601ZuluString('millisecond'),
                // A stock list with no items must encode as {} rather than [].
                'items' => (object) $stock['items'],
                'held' => $stock['held'],
            ])
            ->header('Cache-Control', 'private, no-store');
    }
}
