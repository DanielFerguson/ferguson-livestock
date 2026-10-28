<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Drops;

use App\Actions\Drops\SaveDrop;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Changes;
use App\Mcp\Support\Presenter;
use App\Models\Drop;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Change a drop\'s name, times, delivery days or whether its date is announced on the website. Only the fields you pass change. A published drop cannot be moved to overlap another drop.')]
#[IsDestructive(false)]
#[IsIdempotent]
final class UpdateDropTool extends Tool
{
    public function handle(Request $request, SaveDrop $saveDrop): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'drop_id' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:100'],
            'opens_at' => ['nullable', 'string'],
            'closes_at' => ['nullable', 'string'],
            'clear_closes_at' => ['nullable', 'boolean'],
            'delivery_days' => ['nullable', 'array', 'max:14'],
            'delivery_days.*' => ['string'],
            'announce_date' => ['nullable', 'boolean'],
        ]);

        $drop = Drop::query()->with(['items.product'])->find($arguments->requireInteger('drop_id'));

        if (! $drop instanceof Drop) {
            return Response::error("There is no drop with id {$arguments->requireInteger('drop_id')}. Use list-drops to find one.");
        }

        $changes = array_filter([
            'name' => $arguments->string('name'),
            'opens_at' => $arguments->dateTime('opens_at'),
            'closes_at' => $arguments->dateTime('closes_at'),
            'delivery_days' => $arguments->strings('delivery_days'),
            'announced' => $request->get('announce_date') === null ? null : $arguments->boolean('announce_date'),
        ], fn (mixed $value): bool => $value !== null);

        if ($arguments->boolean('clear_closes_at')) {
            $changes['closes_at'] = null;
        }

        if ($changes === []) {
            return Response::error('Nothing to change. Pass at least one of name, opens_at, closes_at, clear_closes_at, delivery_days or announce_date.');
        }

        return Changes::run('update-drop', $request->all(), fn (): ResponseFactory => Response::structured([
            'updated' => true,
            'drop' => Presenter::drop($saveDrop($drop, $changes)->load('items.product'), withItems: true),
        ]));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'drop_id' => $schema->integer()->description('The drop id.')->required(),
            'name' => $schema->string()->description('New name.'),
            'opens_at' => $schema->string()->description('New opening time, ISO-8601 (no offset = Melbourne).'),
            'closes_at' => $schema->string()->description('New closing time, ISO-8601 (no offset = Melbourne).'),
            'clear_closes_at' => $schema->boolean()->description('Remove the closing time.'),
            'delivery_days' => $schema->array()->items($schema->string())->description('Replaces the delivery days, YYYY-MM-DD.'),
            'announce_date' => $schema->boolean()->description('Show or hide the date on the website (drafts only).'),
        ];
    }
}
