<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Broadcasts;

use App\Actions\Broadcasts\SaveBroadcast;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Changes;
use App\Mcp\Support\Presenter;
use App\Models\Drop;
use App\Models\User;
use App\Sms\DropAnnouncement;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Draft the standard "our next drop opens..." text for a drop, linked to it, ready to check, test and schedule. Sends nothing.')]
#[IsDestructive(false)]
final class DraftDropAnnouncementTool extends Tool
{
    public function handle(Request $request, SaveBroadcast $save): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, ['drop_id' => ['required', 'integer']]);

        $drop = Drop::query()->find($arguments->requireInteger('drop_id'));

        if (! $drop instanceof Drop) {
            return Response::error("There is no drop with id {$arguments->requireInteger('drop_id')}. Use list-drops to find one.");
        }

        return Changes::run('draft-drop-announcement', $request->all(), fn (): ResponseFactory => Response::structured([
            'drafted' => true,
            'broadcast' => Presenter::broadcast($save(null, ['body' => DropAnnouncement::bodyFor($drop), 'drop_id' => $drop->id], $request->user() instanceof User ? $request->user()->id : null)),
        ]));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'drop_id' => $schema->integer()->description('The drop to announce.')->required(),
        ];
    }
}
