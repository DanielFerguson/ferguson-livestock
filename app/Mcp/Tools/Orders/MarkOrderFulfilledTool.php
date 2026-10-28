<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Orders;

use App\Checkout\OrderPayments;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\McpAudit;
use App\Mcp\Support\Presenter;
use App\Models\Order;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Mark a paid order as fulfilled (delivered or collected). Only paid orders can be fulfilled.')]
#[IsDestructive(false)]
#[IsIdempotent]
final class MarkOrderFulfilledTool extends Tool
{
    public function handle(Request $request, OrderPayments $payments): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, ['order' => ['required', 'string', 'max:60']]);

        $code = ltrim(str_ireplace('FL-', '', $arguments->requireString('order')), '-');

        $orders = Order::query()->with(['items.product', 'drop'])
            ->where(fn ($query) => $query
                ->when(strlen($code) === 6, fn ($query) => $query->whereRaw('upper(right(public_id, 6)) = ?', [strtoupper($code)]))
                ->orWhere('public_id', 'ilike', $code))
            ->limit(2)
            ->get();

        if ($orders->count() !== 1) {
            return Response::error($orders->isEmpty() ? 'No order matches that reference.' : 'More than one order matches; use the full public id.');
        }

        $order = $orders->sole();

        if (! $payments->markFulfilled($order)) {
            return Response::error("Order {$order->reference()} is {$order->status->getLabel()}; only a paid order can be marked fulfilled.");
        }

        McpAudit::record('mark-order-fulfilled', $request->all(), 'done');

        return Response::structured(['fulfilled' => true, 'order' => Presenter::order($order->refresh()->load(['items.product', 'drop']))]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'order' => $schema->string()->description('Order reference like FL-7K2QXM.')->required(),
        ];
    }
}
