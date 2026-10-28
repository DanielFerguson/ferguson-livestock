<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Broadcasts;

use App\Actions\Broadcasts\SaveBroadcast;
use App\Mcp\Support\Arguments;
use App\Models\SmsBroadcast;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('How many people a text would reach: everyone subscribed, chosen postcodes, or chosen subscribers. Use it to size an audience before drafting.')]
#[IsReadOnly]
#[IsIdempotent]
final class CountAudienceTool extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'postcodes' => ['nullable', 'array', 'max:50'],
            'postcodes.*' => ['string', 'size:4'],
            'subscriber_ids' => ['nullable', 'array', 'max:200'],
            'subscriber_ids.*' => ['integer'],
        ]);

        $audience = SaveBroadcast::audience([
            'postcodes' => $arguments->strings('postcodes') ?? [],
            'subscribers' => (array) $request->get('subscriber_ids'),
        ]);

        $broadcast = new SmsBroadcast(['body' => 'x', 'audience' => $audience]);

        return Response::structured(['audience' => $broadcast->audienceLabel(), 'recipients' => $broadcast->recipients()->count()]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'postcodes' => $schema->array()->items($schema->string())->description('Send only to subscribers in these four-digit postcodes. Leave out (with no subscriber_ids) for everyone subscribed.'),
            'subscriber_ids' => $schema->array()->items($schema->integer())->description('Send only to these subscribers (for testing). Overrides postcodes.'),
        ];
    }
}
