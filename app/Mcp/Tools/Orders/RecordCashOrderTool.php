<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Orders;

use App\Actions\Orders\RecordCashOrder;
use App\Enums\DeliveryMethod;
use App\Exceptions\CannotRecordOrder;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Changes;
use App\Mcp\Support\Presenter;
use App\Models\Drop;
use App\Models\DropItem;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Record a cash order the owner is putting aside for family or friends who pay in person. Only when the owner asks; never offer cash to customers. It takes stock from the drop like a website order, at the drop’s prices (the delivery fee is added for a delivery), and can be recorded before the drop opens. It is Cash owed until mark-cash-received, unless paid is true. Nothing is texted or emailed.')]
#[IsDestructive(false)]
final class RecordCashOrderTool extends Tool
{
    public function handle(Request $request, RecordCashOrder $recordCashOrder): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'drop_id' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'delivery_method' => ['required', Rule::enum(DeliveryMethod::class)],
            'delivery_day' => ['nullable', 'date_format:Y-m-d'],
            'customer_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'street' => ['nullable', 'string', 'max:255'],
            'suburb' => ['nullable', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:10'],
            'paid' => ['nullable', 'boolean'],
        ]);

        $drop = Drop::query()->with('items.product')->find($arguments->requireInteger('drop_id'));

        if (! $drop instanceof Drop) {
            return Response::error("There is no drop with id {$arguments->requireInteger('drop_id')}. Use list-drops to find one.");
        }

        $items = $request->get('items');
        $deliveryDay = $arguments->string('delivery_day');

        return Changes::run('record-cash-order', $request->all(), fn (): ResponseFactory => Response::structured([
            'recorded' => true,
            'order' => Presenter::order($recordCashOrder(
                drop: $drop,
                quantities: $this->quantities($drop, $items),
                deliveryMethod: DeliveryMethod::from($arguments->requireString('delivery_method')),
                deliveryDay: $deliveryDay === null ? null : CarbonImmutable::parse($deliveryDay),
                customerName: $arguments->requireString('customer_name'),
                phone: $arguments->string('phone'),
                address: ['line1' => $arguments->string('street'), 'city' => $arguments->string('suburb'), 'postal_code' => $arguments->string('postcode')],
                paid: $arguments->boolean('paid'),
            )->load(['items.product', 'drop'])),
        ]));
    }

    /**
     * The requested products as drop item ID => quantity.
     *
     * @return array<int, int>
     *
     * @throws CannotRecordOrder
     */
    private function quantities(Drop $drop, mixed $items): array
    {
        $quantities = [];

        foreach (is_array($items) ? $items : [] as $line) {
            $productId = is_array($line) && is_numeric($line['product_id'] ?? null) ? (int) $line['product_id'] : 0;
            $item = $drop->items->first(fn (DropItem $item): bool => $item->product_id === $productId);

            if (! $item instanceof DropItem) {
                throw new CannotRecordOrder("Product {$productId} isn’t on {$drop->name}. Use get-drop to see what it sells.");
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
            'drop_id' => $schema->integer()->description('The drop id.')->required(),
            'items' => $schema->array()->items($schema->object([
                'product_id' => $schema->integer()->description('A product on the drop, from get-drop.')->required(),
                'quantity' => $schema->integer()->description('How many.')->required(),
            ]))->description('What to put aside. Leave out the delivery fee; it is added for a delivery.')->required(),
            'delivery_method' => $schema->string()->enum(['pickup', 'delivery'])->description('Farm pickup or delivery.')->required(),
            'delivery_day' => $schema->string()->description('For a delivery: one of the drop’s delivery days, YYYY-MM-DD.'),
            'customer_name' => $schema->string()->description('Who the order is for.')->required(),
            'phone' => $schema->string()->description('Their Australian mobile. Required for a delivery.'),
            'street' => $schema->string()->description('For a delivery: the street address.'),
            'suburb' => $schema->string()->description('For a delivery.'),
            'postcode' => $schema->string()->description('For a delivery: four digits.'),
            'paid' => $schema->boolean()->description('True if the cash has already been handed over. Defaults to false (Cash owed).'),
        ];
    }
}
