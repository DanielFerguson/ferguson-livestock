<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Orders;

use App\Mcp\Support\Arguments;
use App\Mcp\Support\Presenter;
use App\Models\Order;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('One order by its reference (FL-XXXXXX), public id or numeric id, with the customer, delivery details, items and Stripe ids.')]
#[IsReadOnly]
#[IsIdempotent]
final class GetOrderTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, ['order' => ['required', 'string', 'max:60']]);

        $reference = $arguments->requireString('order');
        $code = ltrim(str_ireplace('FL-', '', $reference), '-');

        $orders = Order::query()->with(['items.product', 'drop'])
            ->where(fn ($query) => $query
                ->when(strlen($code) === 6, fn ($query) => $query->whereRaw('upper(right(public_id, 6)) = ?', [strtoupper($code)]))
                ->orWhere('public_id', 'ilike', $code)
                ->when(ctype_digit($reference), fn ($query) => $query->orWhereKey((int) $reference)))
            ->limit(2)
            ->get();

        if ($orders->count() > 1) {
            return Response::error("More than one order matches \"{$reference}\". Use the full public id.");
        }

        $order = $orders->first();

        if (! $order instanceof Order) {
            return Response::error("No order matches \"{$reference}\". Use list-orders with a search to find it.");
        }

        return Response::structured(Presenter::order($order, detailed: true));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'order' => $schema->string()->description('Order reference like FL-01H8X…, its public id, or the numeric id.')->required(),
        ];
    }
}
