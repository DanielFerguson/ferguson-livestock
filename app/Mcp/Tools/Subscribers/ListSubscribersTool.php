<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Subscribers;

use App\Mcp\Support\Arguments;
use App\Mcp\Support\Presenter;
use App\Models\Subscriber;
use App\Support\AustralianMobile;
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

#[Description('Search the text list. Filter by name or phone, postcode, or status (subscribed, opted_out).')]
#[IsReadOnly]
#[IsIdempotent]
final class ListSubscribersTool extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'search' => ['nullable', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'size:4'],
            'status' => ['nullable', Rule::in(['subscribed', 'opted_out'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = $arguments->string('search') ?? '';
        $digits = $search === '' ? null : AustralianMobile::searchDigits($search);

        $query = Subscriber::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('first_name', 'ilike', "%{$search}%")
                ->when($digits !== null, fn ($query) => $query->orWhere('phone', 'like', "%{$digits}%"))))
            ->when($arguments->string('postcode') !== null, fn ($query) => $query->where('postcode', $arguments->string('postcode')))
            ->when($arguments->string('status') === 'subscribed', fn ($query) => $query->subscribed())
            ->when($arguments->string('status') === 'opted_out', fn ($query) => $query->whereNotNull('unsubscribed_at'));

        $total = $query->count();

        return Response::structured([
            'total_matching' => $total,
            'subscribers' => $query->latest('id')->limit($arguments->integer('limit') ?? 25)->get()
                ->map(fn (Subscriber $subscriber): array => Presenter::subscriber($subscriber))->all(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Part of a first name, or a phone number as people write it.'),
            'postcode' => $schema->string()->description('Four-digit postcode.'),
            'status' => $schema->string()->enum(['subscribed', 'opted_out'])->description('Only subscribed people, or only those who opted out.'),
            'limit' => $schema->integer()->description('How many to return, 1 to 100. Defaults to 25.'),
        ];
    }
}
