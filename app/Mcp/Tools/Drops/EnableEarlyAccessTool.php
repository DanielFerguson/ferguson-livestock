<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Drops;

use App\Actions\Drops\EnableEarlyAccess;
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

#[Description('Let family and close friends order a drop before it is public, through a secret link. Works while the drop is a draft, announced or scheduled, and keeps working once it opens; orders come out of the same stock. Returns the link for the owner to share themselves: nothing is texted. Asking again returns the same link; pass new_link to replace it, which stops the old one working. Keep the drop unpublished until the public should see it, since a published drop is visible on the order page before it opens.')]
#[IsDestructive(false)]
final class EnableEarlyAccessTool extends Tool
{
    public function handle(Request $request, EnableEarlyAccess $enable): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, ['drop_id' => ['required', 'integer'], 'new_link' => ['sometimes', 'boolean']]);

        $drop = Drop::query()->with(['items.product'])->find($arguments->requireInteger('drop_id'));

        if (! $drop instanceof Drop) {
            return Response::error("There is no drop with id {$arguments->requireInteger('drop_id')}. Use list-drops to find one.");
        }

        return Changes::run('enable-early-access', $request->all(), fn (): ResponseFactory => Response::structured([
            'enabled' => true,
            'drop' => Presenter::drop($enable($drop, $arguments->boolean('new_link'))),
        ]));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'drop_id' => $schema->integer()->description('The drop id.')->required(),
            'new_link' => $schema->boolean()->description('Replace the link, so the old one stops working. Default false.'),
        ];
    }
}
