<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Orders;

use App\Enums\OrderStatus;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Presenter;
use App\Models\Order;
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

#[Description('List orders, newest first. Filter by drop, status (pending, processing, paid, fulfilled, expired, failed) or a search on name, email, phone or reference (FL-XXXXXX).')]
#[IsReadOnly]
#[IsIdempotent]
final class ListOrdersTool extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'drop_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = $arguments->string('search') ?? '';

        $orders = Order::query()
            ->with(['items.product', 'drop'])
            ->when($arguments->integer('drop_id') !== null, fn ($query) => $query->where('drop_id', $arguments->integer('drop_id')))
            ->when($arguments->string('status') !== null, fn ($query) => $query->where('status', $arguments->string('status')))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('customer_name', 'ilike', "%{$search}%")
                ->orWhere('email', 'ilike', "%{$search}%")
                ->when(preg_replace('/\D/', '', $search) !== '', fn ($query) => $query->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $search).'%'))
                ->orWhereRaw('upper(right(public_id, 6)) = ?', [strtoupper(ltrim(str_ireplace('FL-', '', $search), '-'))])))
            ->latest('id')
            ->limit($arguments->integer('limit') ?? 25)
            ->get();

        return Response::structured(['orders' => $orders->map(fn (Order $order): array => Presenter::order($order))->all()]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'drop_id' => $schema->integer()->description('Only orders in this drop.'),
            'status' => $schema->string()->enum(array_column(OrderStatus::cases(), 'value'))->description('Only orders with this status.'),
            'search' => $schema->string()->description('Name, email, phone digits or order reference.'),
            'limit' => $schema->integer()->description('How many to return, 1 to 100. Defaults to 25.'),
        ];
    }
}
