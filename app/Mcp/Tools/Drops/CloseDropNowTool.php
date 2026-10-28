<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Drops;

use App\Mcp\Support\Arguments;
use App\Mcp\Support\Changes;
use App\Mcp\Support\Presenter;
use App\Models\Drop;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Stop taking orders on an open drop right now. Customers already at the payment page can still finish paying. Cannot be undone. Only for a drop that is live or sold out.')]
#[IsDestructive]
final class CloseDropNowTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, ['drop_id' => ['required', 'integer']]);

        $drop = Drop::query()->with(['items.product'])->find($arguments->requireInteger('drop_id'));

        if (! $drop instanceof Drop) {
            return Response::error("There is no drop with id {$arguments->requireInteger('drop_id')}. Use list-drops to find one.");
        }

        if (! $drop->status()->isOpen()) {
            return Response::error("This drop is {$drop->status()->getLabel()}, not open, so there is nothing to close.");
        }

        return Changes::run('close-drop-now', $request->all(), function () use ($drop): ResponseFactory {
            $drop->closeNow();

            return Response::structured(['closed' => true, 'drop' => Presenter::drop($drop->refresh())]);
        });
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
