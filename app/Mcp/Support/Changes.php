<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Exceptions\CannotChangeBroadcast;
use App\Exceptions\CannotChangeDrop;
use App\Exceptions\CannotReply;
use App\Exceptions\SmsNotSent;
use Closure;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Runs a tool's change, turns the app's "you can't do that" exceptions into messages the AI client can act on,
 * and records what happened.
 */
final class Changes
{
    /**
     * @param  array<string, mixed>  $arguments  What the tool was asked to do, for the audit log.
     * @param  Closure(): (Response|ResponseFactory)  $change
     */
    public static function run(string $tool, array $arguments, Closure $change): Response|ResponseFactory
    {
        try {
            $response = $change();
        } catch (CannotChangeDrop|CannotChangeBroadcast|CannotReply|SmsNotSent $exception) {
            McpAudit::record($tool, $arguments, 'refused: '.$exception->getMessage());

            return Response::error($exception->getMessage());
        }

        McpAudit::record($tool, $arguments, 'done');

        return $response;
    }
}
