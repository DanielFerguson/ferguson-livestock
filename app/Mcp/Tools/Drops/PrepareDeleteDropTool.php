<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Drops;

use App\Mcp\Support\Arguments;
use App\Mcp\Support\ConfirmationTokens;
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

#[Description('Step 1 of deleting a drop. Says whether it can be deleted (only drafts, announced or scheduled drops with no orders) and returns a confirmation_token for delete-drop.')]
#[IsDestructive(false)]
#[IsIdempotent]
final class PrepareDeleteDropTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public static function fingerprint(Drop $drop): array
    {
        return ['drop' => $drop->id, 'status' => $drop->status()->value, 'items' => $drop->items->count(), 'orders' => $drop->orders()->count()];
    }

    public function handle(Request $request, ConfirmationTokens $tokens): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, ['drop_id' => ['required', 'integer']]);

        $drop = Drop::query()->with(['items.product'])->find($arguments->requireInteger('drop_id'));

        if (! $drop instanceof Drop) {
            return Response::error("There is no drop with id {$arguments->requireInteger('drop_id')}. Use list-drops to find one.");
        }

        $reason = $drop->whyItCantBeDeleted();

        return Response::structured([
            'can_delete' => $reason === null,
            'reason' => $reason,
            'will_happen' => "Deletes the drop \"{$drop->name}\" and its items permanently.",
            'drop' => Presenter::drop($drop, withItems: true),
            'confirmation_token' => $reason === null ? $tokens->issue('delete-drop', "drop:{$drop->id}", self::fingerprint($drop)) : null,
        ]);
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
