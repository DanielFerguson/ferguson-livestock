<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Subscribers;

use App\Sms\SubscriberStats;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('How the text list is doing: totals, opt-outs, the biggest postcodes, sign-ups per week for the last 12 weeks, and where consent was collected.')]
#[IsReadOnly]
#[IsIdempotent]
final class GetSubscriberStatsTool extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        return Response::structured(SubscriberStats::summary());
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
