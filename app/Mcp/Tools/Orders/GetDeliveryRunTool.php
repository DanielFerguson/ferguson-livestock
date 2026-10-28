<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Orders;

use App\Mcp\Support\Arguments;
use App\Mcp\Support\Presenter;
use App\Models\Drop;
use App\Models\Order;
use App\Support\DeliveryRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('The packing and delivery list for a drop: paid orders grouped into deliveries (by day, suburb, postcode) and farm pickups, with what to pack. Set format to csv for the spreadsheet version.')]
#[IsReadOnly]
#[IsIdempotent]
final class GetDeliveryRunTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'drop_id' => ['required', 'integer'],
            'format' => ['nullable', Rule::in(['rows', 'csv'])],
        ]);

        $drop = Drop::query()->find($arguments->requireInteger('drop_id'));

        if (! $drop instanceof Drop) {
            return Response::error("There is no drop with id {$arguments->requireInteger('drop_id')}.");
        }

        $run = DeliveryRun::forDrop($drop);

        if ($arguments->string('format') === 'csv') {
            return Response::structured(['drop' => $drop->name, 'csv' => $run->toCsv()]);
        }

        $present = fn (Order $order): array => [
            'order' => $order->reference(),
            'customer' => $order->customer_name,
            'phone' => $order->phone,
            'method' => $order->delivery_method->value,
            'day' => $order->delivery_day?->toDateString(),
            'address' => $order->shipping_address,
            'pack' => DeliveryRun::items($order),
            'total' => Presenter::money($order->total),
            'fulfilled' => $order->fulfilled_at !== null,
        ];

        return Response::structured([
            'drop' => $drop->name,
            'deliveries' => $run->deliveries->map($present)->all(),
            'pickups' => $run->pickups->map($present)->all(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'drop_id' => $schema->integer()->description('The drop id.')->required(),
            'format' => $schema->string()->enum(['rows', 'csv'])->description('rows (default) or csv.'),
        ];
    }
}
