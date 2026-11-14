<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Orders;

use App\Actions\Orders\GiveFreeItems;
use App\Exceptions\CannotChangeOrder;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Changes;
use App\Mcp\Support\OrderReference;
use App\Mcp\Support\Presenter;
use App\Models\DropItem;
use App\Models\Order;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Give a customer something free on their order, like a sample pack at pickup. Only when the owner asks. It comes out of the drop’s stock, so inventory stays right, but what they paid doesn’t change and nothing is texted or emailed. Works on Paid, Fulfilled and Cash owed orders, after the drop has closed too. Undo with remove-free-item.')]
#[IsDestructive(false)]
final class GiveFreeItemsTool extends Tool
{
    public function handle(Request $request, GiveFreeItems $giveFreeItems): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'order' => ['required', 'string', 'max:60'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $orders = OrderReference::matches($arguments->requireString('order'));

        if ($orders->count() !== 1) {
            return Response::error($orders->isEmpty() ? 'No order matches that reference.' : 'More than one order matches; use the full public id.');
        }

        $order = $orders->sole();
        $items = $request->get('items');

        return Changes::run('give-free-items', $request->all(), fn (): ResponseFactory => Response::structured([
            'given' => true,
            'order' => Presenter::order($giveFreeItems($order, $this->quantities($order, $items))),
        ]));
    }

    /**
     * The requested products as drop item ID => quantity, on the order's drop.
     *
     * @return array<int, int>
     *
     * @throws CannotChangeOrder
     */
    private function quantities(Order $order, mixed $items): array
    {
        $drop = $order->drop->loadMissing('items');
        $quantities = [];

        foreach (is_array($items) ? $items : [] as $line) {
            $productId = is_array($line) && is_numeric($line['product_id'] ?? null) ? (int) $line['product_id'] : 0;
            $item = $drop->items->first(fn (DropItem $item): bool => $item->product_id === $productId);

            if (! $item instanceof DropItem) {
                throw new CannotChangeOrder("Product {$productId} isn’t on {$drop->name}. Use get-drop to see what it sells.");
            }

            $quantities[$item->id] = ($quantities[$item->id] ?? 0) + (is_array($line) && is_numeric($line['quantity'] ?? null) ? (int) $line['quantity'] : 0);
        }

        return $quantities;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'order' => $schema->string()->description('Order reference like FL-7K2QXM.')->required(),
            'items' => $schema->array()->items($schema->object([
                'product_id' => $schema->integer()->description('A product on the order’s drop, from get-drop.')->required(),
                'quantity' => $schema->integer()->description('How many.')->required(),
            ]))->description('What to give.')->required(),
        ];
    }
}
