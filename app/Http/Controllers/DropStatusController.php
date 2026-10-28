<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Stock\DropSnapshot;
use Illuminate\Http\JsonResponse;

/**
 * The live stock feed. Stateless and public, so Cloudflare may cache it for a second too.
 */
class DropStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $snapshot = DropSnapshot::current();

        // A stock list with no items must encode as {} rather than [].
        $payload = $snapshot;
        $payload['items'] = (object) $snapshot['items'];

        return response()
            ->json($payload)
            ->header('Cache-Control', 'public, max-age=1, s-maxage=1');
    }
}
