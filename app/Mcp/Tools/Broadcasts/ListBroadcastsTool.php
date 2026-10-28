<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Broadcasts;

use App\Mcp\Support\Arguments;
use App\Mcp\Support\Presenter;
use App\Models\SmsBroadcast;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List text broadcasts, newest first, with status (draft, scheduled, sending, sent), audience and delivery counts.')]
#[IsReadOnly]
#[IsIdempotent]
final class ListBroadcastsTool extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $arguments = Arguments::validate($request, ['limit' => ['nullable', 'integer', 'min:1', 'max:50']]);

        $broadcasts = SmsBroadcast::query()->latest('id')->limit($arguments->integer('limit') ?? 15)->get();

        return Response::structured(['broadcasts' => $broadcasts->map(fn (SmsBroadcast $broadcast): array => Presenter::broadcast($broadcast))->all()]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('How many to return, 1 to 50. Defaults to 15.'),
        ];
    }
}
