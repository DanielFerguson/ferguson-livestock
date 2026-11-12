<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;

/**
 * Finds an order by the reference the owner reads out (FL-7K2QXM) or its full public id.
 */
final class OrderReference
{
    /**
     * Up to two matches, so a caller can tell "none" and "more than one" apart from "exactly this one".
     *
     * @return Collection<int, Order>
     */
    public static function matches(string $reference): Collection
    {
        $code = ltrim(str_ireplace('FL-', '', $reference), '-');

        return Order::query()->with(['items.product', 'drop'])
            ->where(fn ($query) => $query
                ->when(strlen($code) === 6, fn ($query) => $query->whereRaw('upper(right(public_id, 6)) = ?', [strtoupper($code)]))
                ->orWhere('public_id', 'ilike', $code))
            ->limit(2)
            ->get();
    }
}
