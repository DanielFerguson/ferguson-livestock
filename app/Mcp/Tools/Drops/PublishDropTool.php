<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Drops;

use App\Actions\Drops\PublishDrop;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Changes;
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

#[Description('Step 2 of going live: publish the drop. Needs the confirmation_token from prepare-publish-drop, valid for 10 minutes and for the drop exactly as previewed. Customers can order from its opening time.')]
#[IsDestructive]
final class PublishDropTool extends Tool
{
    public function handle(Request $request, PublishDrop $publish, ConfirmationTokens $tokens): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'drop_id' => ['required', 'integer'],
            'confirmation_token' => ['required', 'string'],
            'ignore_preflight_problems' => ['nullable', 'boolean'],
        ]);

        $drop = Drop::query()->with(['items.product'])->find($arguments->requireInteger('drop_id'));

        if (! $drop instanceof Drop) {
            return Response::error("There is no drop with id {$arguments->requireInteger('drop_id')}. Use list-drops to find one.");
        }

        $confirmed = $tokens->consume(
            $arguments->requireString('confirmation_token'),
            'publish-drop',
            "drop:{$drop->id}",
            PreparePublishDropTool::fingerprint($drop, $arguments->boolean('ignore_preflight_problems')),
        );

        if (! $confirmed) {
            return Response::error('That confirmation_token is not valid for this drop as it is now (it may be used, expired, or the drop changed). Call prepare-publish-drop again and show the owner the result.');
        }

        return Changes::run('publish-drop', $request->all(), fn (): ResponseFactory => Response::structured([
            'published' => true,
            'drop' => Presenter::drop($publish($drop)->load('items.product'), withItems: true),
        ]));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'drop_id' => $schema->integer()->description('The drop id.')->required(),
            'confirmation_token' => $schema->string()->description('From prepare-publish-drop.')->required(),
            'ignore_preflight_problems' => $schema->boolean()->description('Must match what you passed to prepare-publish-drop.'),
        ];
    }
}
