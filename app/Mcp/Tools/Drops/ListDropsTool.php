<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Drops;

use App\Enums\DropStatus;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Presenter;
use App\Models\Drop;
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

#[Description('List drops, newest first. Filter by status: draft, announced, scheduled, live, sold_out or closed.')]
#[IsReadOnly]
#[IsIdempotent]
final class ListDropsTool extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'status' => ['nullable', Rule::enum(DropStatus::class)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $drops = Drop::query()->with('items.product')->latest('opens_at')->get()
            ->when($arguments->string('status') !== null, fn ($drops) => $drops->filter(fn (Drop $drop): bool => $drop->status()->value === $arguments->string('status')))
            ->take($arguments->integer('limit') ?? 15)
            ->map(fn (Drop $drop): array => Presenter::drop($drop))
            ->values()
            ->all();

        return Response::structured(['drops' => $drops]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(array_column(DropStatus::cases(), 'value'))->description('Only drops with this status.'),
            'limit' => $schema->integer()->description('How many to return, 1 to 50. Defaults to 15.'),
        ];
    }
}
