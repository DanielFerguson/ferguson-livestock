<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Orders;

use App\Checkout\OrderPayments;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\McpAudit;
use App\Mcp\Support\OrderReference;
use App\Mcp\Support\Presenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Mark a Cash owed order as paid, once the owner says the cash has been handed over. Only Cash owed orders change.')]
#[IsDestructive(false)]
#[IsIdempotent]
final class MarkCashReceivedTool extends Tool
{
    public function handle(Request $request, OrderPayments $payments): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, ['order' => ['required', 'string', 'max:60']]);

        $orders = OrderReference::matches($arguments->requireString('order'));

        if ($orders->count() !== 1) {
            return Response::error($orders->isEmpty() ? 'No order matches that reference.' : 'More than one order matches; use the full public id.');
        }

        $order = $orders->sole();

        if (! $payments->markCashReceived($order)) {
            return Response::error("Order {$order->reference()} is {$order->status->getLabel()}; only a Cash owed order can be marked paid.");
        }

        McpAudit::record('mark-cash-received', $request->all(), 'done');

        return Response::structured(['paid' => true, 'order' => Presenter::order($order->refresh()->load(['items.product', 'drop']))]);
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
