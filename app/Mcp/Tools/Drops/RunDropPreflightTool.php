<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Drops;

use App\Actions\RunDropPreflight;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Changes;
use App\Models\Drop;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Run the pre-flight check on a drop now: stock, delivery days, Stripe prices and webhook, and site infrastructure. Saves the result on the drop and returns whether it passed and any problems to fix.')]
#[IsDestructive(false)]
#[IsIdempotent]
final class RunDropPreflightTool extends Tool
{
    public function handle(Request $request, RunDropPreflight $preflight): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, ['drop_id' => ['required', 'integer']]);

        $drop = Drop::query()->with(['items.product'])->find($arguments->requireInteger('drop_id'));

        if (! $drop instanceof Drop) {
            return Response::error("There is no drop with id {$arguments->requireInteger('drop_id')}. Use list-drops to find one.");
        }

        return Changes::run('run-drop-preflight', $request->all(), fn (): ResponseFactory => Response::structured($preflight($drop)));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'drop_id' => $schema->integer()->description('The drop id.')->required(),
        ];
    }
}
