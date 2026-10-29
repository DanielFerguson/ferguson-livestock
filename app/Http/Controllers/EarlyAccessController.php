<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Drop;
use Illuminate\Contracts\View\View;

/**
 * The order page for a drop that isn't public yet, reached through its secret early-access link. Anyone with
 * the link can order; a wrong, replaced or switched-off link, or a drop that has closed, is a plain 404.
 */
class EarlyAccessController extends Controller
{
    public function __invoke(string $token): View
    {
        $drop = Drop::query()->where('early_access_token', $token)->first();

        abort_unless($drop instanceof Drop && $drop->acceptsEarlyOrders(), 404);

        return view('pages.order', [
            'drop' => $drop,
            'announcedLabel' => null,
            'earlyAccessToken' => $token,
        ]);
    }
}
