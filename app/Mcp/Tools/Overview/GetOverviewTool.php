<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Overview;

use App\Mcp\Support\Presenter;
use App\Models\Drop;
use App\Models\StripeEvent;
use App\Models\Subscriber;
use App\Sms\ConversationList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Start here. A snapshot of the business right now: the drop customers can order from (or the next one), how many conversations need attention, Stripe events that failed to process, and the size of the text list.')]
#[IsReadOnly]
#[IsIdempotent]
final class GetOverviewTool extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $drop = Drop::featured();

        $conversations = ConversationList::counts();

        return Response::structured([
            'now' => Presenter::time(now()),
            'featured_drop' => $drop instanceof Drop ? Presenter::drop($drop, withItems: true) : null,
            'next_unpublished_drop' => ($next = Drop::query()->whereNull('published_at')->where('opens_at', '>', now())->oldest('opens_at')->first()) instanceof Drop
                ? Presenter::drop($next)
                : null,
            'conversations' => [
                'needing_attention' => ConversationList::attentionCount(),
                'total' => $conversations['all'] ?? 0,
            ],
            'stripe_events' => [
                'unprocessed' => StripeEvent::query()->whereNull('processed_at')->count(),
                'failing' => StripeEvent::query()->whereNull('processed_at')->whereNotNull('last_error')->count(),
            ],
            'subscribers' => [
                'subscribed' => Subscriber::query()->subscribed()->count(),
                'opted_out' => Subscriber::query()->whereNotNull('unsubscribed_at')->count(),
            ],
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
