<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Drops;

use App\Actions\Drops\DeleteDrop;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Changes;
use App\Mcp\Support\ConfirmationTokens;
use App\Models\Drop;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Step 2 of deleting a drop: permanently delete it. Needs the confirmation_token from prepare-delete-drop. Cannot be undone.')]
#[IsDestructive]
final class DeleteDropTool extends Tool
{
    public function handle(Request $request, DeleteDrop $delete, ConfirmationTokens $tokens): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'drop_id' => ['required', 'integer'],
            'confirmation_token' => ['required', 'string'],
        ]);

        $drop = Drop::query()->with(['items.product'])->find($arguments->requireInteger('drop_id'));

        if (! $drop instanceof Drop) {
            return Response::error("There is no drop with id {$arguments->requireInteger('drop_id')}. Use list-drops to find one.");
        }

        $confirmed = $tokens->consume($arguments->requireString('confirmation_token'), 'delete-drop', "drop:{$drop->id}", PrepareDeleteDropTool::fingerprint($drop));

        if (! $confirmed) {
            return Response::error('That confirmation_token is not valid for this drop as it is now. Call prepare-delete-drop again.');
        }

        return Changes::run('delete-drop', $request->all(), function () use ($delete, $drop): ResponseFactory {
            $delete($drop);

            return Response::structured(['deleted' => true, 'name' => $drop->name]);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'drop_id' => $schema->integer()->description('The drop id.')->required(),
            'confirmation_token' => $schema->string()->description('From prepare-delete-drop.')->required(),
        ];
    }
}
