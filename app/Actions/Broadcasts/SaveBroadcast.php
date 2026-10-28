<?php

declare(strict_types=1);

namespace App\Actions\Broadcasts;

use App\Exceptions\CannotChangeBroadcast;
use App\Models\Drop;
use App\Models\SmsBroadcast;
use App\Models\Subscriber;
use App\Sms\SmsText;

/**
 * Drafts a broadcast, or edits one that hasn't started sending. It never schedules or sends: that is a separate,
 * deliberate step, because scheduling a send soon is itself a commitment.
 *
 * @phpstan-type Changes array{body?: string, postcodes?: list<string>, subscriber_ids?: list<int>, drop_id?: int|null}
 */
final class SaveBroadcast
{
    public const int MAX_CHARACTERS = 640;

    /**
     * @param  Changes  $changes  Only the keys present change. Chosen subscribers win over postcodes.
     *
     * @throws CannotChangeBroadcast
     */
    public function __invoke(?SmsBroadcast $broadcast, array $changes, ?int $createdBy = null): SmsBroadcast
    {
        if ($broadcast instanceof SmsBroadcast && ! $broadcast->status()->isEditable()) {
            throw new CannotChangeBroadcast("This broadcast is {$broadcast->status()->getLabel()} and can no longer be edited.");
        }

        $attributes = [];

        if (array_key_exists('body', $changes)) {
            $body = SmsText::tidy($changes['body']);

            if ($body === '' || mb_strlen($body) > self::MAX_CHARACTERS) {
                throw new CannotChangeBroadcast('The message must be 1 to '.self::MAX_CHARACTERS.' characters. Do not add the "Ferguson Livestock:" prefix or the STOP line; they are added for you.');
            }

            $attributes['body'] = $body;
        } elseif (! $broadcast instanceof SmsBroadcast) {
            throw new CannotChangeBroadcast('A new broadcast needs a message.');
        }

        if (array_key_exists('postcodes', $changes) || array_key_exists('subscriber_ids', $changes)) {
            $attributes['audience'] = self::audience([
                'postcodes' => $changes['postcodes'] ?? $broadcast?->postcodes() ?? [],
                'subscribers' => $changes['subscriber_ids'] ?? $broadcast?->subscriberIds() ?? [],
            ]);

            $ids = $attributes['audience']['subscribers'];

            if ($ids !== [] && Subscriber::query()->whereKey($ids)->count() !== count(array_unique($ids))) {
                throw new CannotChangeBroadcast('One of the chosen subscriber ids does not exist. Use list-subscribers to find them.');
            }
        }

        if (array_key_exists('drop_id', $changes)) {
            if ($changes['drop_id'] !== null && ! Drop::query()->whereKey($changes['drop_id'])->exists()) {
                throw new CannotChangeBroadcast("There is no drop with id {$changes['drop_id']}.");
            }

            $attributes['drop_id'] = $changes['drop_id'];
        }

        if ($broadcast instanceof SmsBroadcast) {
            $broadcast->update($attributes);

            return $broadcast->refresh();
        }

        return SmsBroadcast::query()->create([
            'audience' => ['postcodes' => [], 'subscribers' => []],
            ...$attributes,
            'created_by' => $createdBy,
        ])->refresh();
    }

    /**
     * Postcodes as strings and subscriber ids as integers, whatever the form or client sent.
     *
     * @param  array<array-key, mixed>  $audience
     * @return array{postcodes: list<string>, subscribers: list<int>}
     */
    public static function audience(array $audience): array
    {
        $postcodes = array_filter(
            is_array($audience['postcodes'] ?? null) ? $audience['postcodes'] : [],
            fn (mixed $postcode): bool => is_string($postcode) || is_int($postcode),
        );
        $subscribers = array_filter(
            is_array($audience['subscribers'] ?? null) ? $audience['subscribers'] : [],
            fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)),
        );

        return [
            'postcodes' => array_values(array_map(strval(...), $postcodes)),
            'subscribers' => array_values(array_map(intval(...), $subscribers)),
        ];
    }
}
