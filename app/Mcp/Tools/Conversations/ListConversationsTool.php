<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Conversations;

use App\Enums\ConversationFilter;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Presenter;
use App\Sms\ConversationList;
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

#[Description('The inbox: people who have texted in, attention-needing first. Filter by all, needs_reply or unread (which includes flagged follow-ups), or search by name or number.')]
#[IsReadOnly]
#[IsIdempotent]
final class ListConversationsTool extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'filter' => ['nullable', Rule::enum(ConversationFilter::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = $arguments->string('search') ?? '';
        $filter = ConversationFilter::tryFrom($arguments->string('filter') ?? '') ?? ConversationFilter::All;

        return Response::structured([
            'counts' => ConversationList::counts($search),
            'conversations' => ConversationList::summaries($search, $filter)
                ->map(fn (array $summary): array => Presenter::conversation($summary))->all(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'filter' => $schema->string()->enum(array_column(ConversationFilter::cases(), 'value'))->description('all (default), needs_reply or unread.'),
            'search' => $schema->string()->description('Name or phone number.'),
        ];
    }
}
