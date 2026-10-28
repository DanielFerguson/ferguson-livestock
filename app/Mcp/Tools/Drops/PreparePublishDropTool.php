<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Drops;

use App\Actions\Drops\PublishDrop;
use App\Actions\RunDropPreflight;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\ConfirmationTokens;
use App\Mcp\Support\Presenter;
use App\Models\Drop;
use App\Models\DropItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Step 1 of going live. Runs a fresh pre-flight check and shows exactly what publishing will do. Returns a confirmation_token for publish-drop only when the drop can be published and the pre-flight passed (or you pass ignore_preflight_problems with the owner\'s agreement). Show the owner the result before calling publish-drop.')]
#[IsDestructive(false)]
#[IsIdempotent]
final class PreparePublishDropTool extends Tool
{
    /**
     * What the owner is shown and agrees to, so changing any of it voids the confirmation token.
     *
     * @return array<string, mixed>
     */
    public static function fingerprint(Drop $drop, bool $ignoringPreflight): array
    {
        $drop->load('items');

        return [
            'drop' => $drop->id,
            'name' => $drop->name,
            'opens_at' => $drop->opens_at->toIso8601String(),
            'closes_at' => $drop->closes_at?->toIso8601String(),
            'delivery_days' => $drop->delivery_days,
            'items' => $drop->items->map(fn (DropItem $item): array => $item->only('id', 'product_id', 'price', 'stripe_price_id', 'quantity', 'max_per_order'))->all(),
            'preflight' => $drop->preflight_report['problems'] ?? null,
            'ignoring_preflight' => $ignoringPreflight,
        ];
    }

    public function handle(Request $request, PublishDrop $publish, RunDropPreflight $preflight, ConfirmationTokens $tokens): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'drop_id' => ['required', 'integer'],
            'ignore_preflight_problems' => ['nullable', 'boolean'],
        ]);

        $drop = Drop::query()->with(['items.product'])->find($arguments->requireInteger('drop_id'));

        if (! $drop instanceof Drop) {
            return Response::error("There is no drop with id {$arguments->requireInteger('drop_id')}. Use list-drops to find one.");
        }

        $blocker = $publish->blocker($drop);

        if ($blocker !== null) {
            return Response::structured(['can_publish' => false, 'blocker' => $blocker, 'drop' => Presenter::drop($drop, withItems: true)]);
        }

        $report = $preflight($drop);
        $drop->refresh();
        $ignoring = $arguments->boolean('ignore_preflight_problems');
        $ready = $report['passed'] || $ignoring;

        return Response::structured([
            'can_publish' => $ready,
            'preflight' => $report,
            'will_happen' => 'The drop goes on sale to customers'.($drop->opens_at->isFuture() ? ' at its opening time.' : ' immediately, because its opening time has already passed.'),
            'drop' => Presenter::drop($drop, withItems: true),
            'confirmation_token' => $ready ? $tokens->issue('publish-drop', "drop:{$drop->id}", self::fingerprint($drop, $ignoring)) : null,
            'next' => $ready ? 'Show the owner this, and call publish-drop with the confirmation_token once they say yes.' : 'Fix the pre-flight problems, or pass ignore_preflight_problems if the owner accepts them.',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'drop_id' => $schema->integer()->description('The drop id.')->required(),
            'ignore_preflight_problems' => $schema->boolean()->description("Allow publishing even though the pre-flight found problems. Only with the owner's explicit agreement."),
        ];
    }
}
