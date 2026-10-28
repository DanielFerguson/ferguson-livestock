<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Drops;

use App\Mcp\Support\Arguments;
use App\Mcp\Support\Presenter;
use App\Models\Drop;
use App\Models\DropItem;
use App\Stock\DropSales;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('One drop in full: dates, delivery days, the latest pre-flight result and every item with its price, Stripe price, stock, sales and revenue.')]
#[IsReadOnly]
#[IsIdempotent]
final class GetDropTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, ['drop_id' => ['required', 'integer']]);

        $drop = Drop::query()->with(['items.product', 'items.orderItems.order'])->find($arguments->requireInteger('drop_id'));

        if (! $drop instanceof Drop) {
            return Response::error("There is no drop with id {$arguments->requireInteger('drop_id')}. Use list-drops to find one.");
        }

        $presented = Presenter::drop($drop);

        $presented['items'] = $drop->items->map(fn (DropItem $item): array => [
            ...Presenter::item($item),
            'sales' => DropSales::forItem($item),
        ])->values()->all();

        return Response::structured($presented);
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
