<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Drops;

use App\Actions\Drops\RemoveDropItem;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Changes;
use App\Models\DropItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Take a product off a drop. Refused if customers hold or have bought it, or it is on any order; set its quantity to 0 instead.')]
#[IsDestructive]
final class RemoveDropItemTool extends Tool
{
    public function handle(Request $request, RemoveDropItem $removeDropItem): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'drop_id' => ['required', 'integer'],
            'product_id' => ['required', 'integer'],
        ]);

        $item = DropItem::query()->with('product')
            ->where('drop_id', $arguments->requireInteger('drop_id'))
            ->where('product_id', $arguments->requireInteger('product_id'))
            ->first();

        if (! $item instanceof DropItem) {
            return Response::error('That product is not on that drop. Use get-drop to see its items.');
        }

        return Changes::run('remove-drop-item', $request->all(), function () use ($removeDropItem, $item): ResponseFactory {
            $removeDropItem($item);

            return Response::structured(['removed' => true, 'product' => $item->product->name]);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'drop_id' => $schema->integer()->description('The drop id.')->required(),
            'product_id' => $schema->integer()->description('The product to remove.')->required(),
        ];
    }
}
