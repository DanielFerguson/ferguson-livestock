<?php

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

        return response()
            ->json([...$snapshot, 'items' => (object) $snapshot['items']])
            ->header('Cache-Control', 'public, max-age=1, s-maxage=1');
    }
}
