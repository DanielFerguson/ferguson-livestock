<?php

declare(strict_types=1);

namespace App\Sms;

use App\Enums\ConversationFilter;
use App\Enums\SmsDirection;
use App\Models\ConversationState;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use Illuminate\Support\Collection;

/**
 * The people who have texted the shop's number, and each conversation with them: what they sent, what was sent
 * to them (broadcasts included) and what was answered, grouped by their phone number.
 *
 * The list shows each person's last real message, not the last broadcast, and broadcasts never move a
 * conversation, so announcing a drop doesn't bury the questions waiting for an answer.
 *
 * @phpstan-type Summary array{phone: string, name: ?string, last: SmsMessage, unread: int, optedOut: bool, followUp: bool, needsReply: bool, attention: bool}
 */
final class ConversationList
{
    private const int LIMIT = 100;

    /**
     * Everyone who has texted in. Conversations that need attention come first, then the most recent.
     *
     * @return Collection<int, Summary>
     */
    public static function summaries(string $search = '', ConversationFilter $filter = ConversationFilter::All): Collection
    {
        return self::build(self::phones($search))
            ->filter(fn (array $summary): bool => match ($filter) {
                ConversationFilter::All => true,
                ConversationFilter::NeedsReply => $summary['needsReply'],
                ConversationFilter::Unread => $summary['unread'] > 0 || $summary['followUp'],
            })
            ->sort(fn (array $a, array $b): int => [$b['attention'], $b['last']->created_at] <=> [$a['attention'], $a['last']->created_at])
            ->take(self::LIMIT)
            ->values();
    }

    /**
     * How many conversations each filter would show, for the filter's labels.
     *
     * @return array<string, int>
     */
    public static function counts(string $search = ''): array
    {
        $all = self::build(self::phones($search));

        return [
            ConversationFilter::All->value => $all->count(),
            ConversationFilter::NeedsReply->value => $all->where('needsReply', true)->count(),
            ConversationFilter::Unread->value => $all->filter(fn (array $summary): bool => $summary['unread'] > 0 || $summary['followUp'])->count(),
        ];
    }

    /**
     * @return Summary|null
     */
    public static function summary(string $phone): ?array
    {
        return self::build(collect([$phone]))->first();
    }

    /**
     * Everything said between the shop and one number, oldest first.
     *
     * @return Collection<int, SmsMessage>
     */
    public static function thread(string $phone): Collection
    {
        return SmsMessage::query()->where('contact_phone', $phone)->with('broadcast')->orderBy('created_at')->orderBy('id')->get();
    }

    public static function markRead(string $phone): void
    {
        SmsMessage::query()->where('contact_phone', $phone)->where('direction', SmsDirection::Inbound)->whereNull('read_at')->update(['read_at' => now()]);
    }

    /**
     * How many conversations are waiting for a look: texts nobody has opened, or flagged to come back to.
     */
    public static function attentionCount(): int
    {
        $unread = SmsMessage::query()->where('direction', SmsDirection::Inbound)->whereNull('read_at')->distinct()->pluck('contact_phone');
        $flagged = ConversationState::query()->whereNotNull('follow_up_at')->pluck('contact_phone');

        return $unread->merge($flagged)->unique()->count();
    }

    /**
     * @param  Collection<int, string>  $phones
     * @return Collection<int, Summary>
     */
    private static function build(Collection $phones): Collection
    {
        $latest = SmsMessage::query()
            ->whereIn('contact_phone', $phones)
            ->whereNull('sms_broadcast_id')
            ->orderBy('contact_phone')->orderByDesc('created_at')->orderByDesc('id')
            ->selectRaw('distinct on (contact_phone) sms_messages.*')
            ->get();

        $unread = SmsMessage::query()
            ->whereIn('contact_phone', $phones)
            ->where('direction', SmsDirection::Inbound)->whereNull('read_at')
            ->selectRaw('contact_phone, count(*) as unread')->groupBy('contact_phone')
            ->pluck('unread', 'contact_phone');

        $states = ConversationState::query()->whereIn('contact_phone', $phones)->get()->keyBy('contact_phone');
        $subscribers = Subscriber::query()->whereIn('phone', $phones)->get()->keyBy('phone');

        return $latest->map(function (SmsMessage $last) use ($unread, $states, $subscribers): array {
            $state = $states->get($last->contact_phone);
            $subscriber = $subscribers->get($last->contact_phone);
            $unreadCount = is_numeric($count = $unread->get($last->contact_phone)) ? (int) $count : 0;
            $followUp = $state?->follow_up_at !== null;
            $optedOut = $subscriber?->isSubscribed() === false;
            // Someone who has opted out can't be answered, so nothing is waiting on you.
            $needsReply = ! $optedOut && $last->direction === SmsDirection::Inbound && ($state?->done_at === null || $last->created_at->greaterThan($state->done_at));

            return [
                'phone' => $last->contact_phone,
                'name' => $subscriber?->first_name,
                'last' => $last,
                'unread' => $unreadCount,
                'optedOut' => $optedOut,
                'followUp' => $followUp,
                'needsReply' => $needsReply,
                'attention' => $unreadCount > 0 || $followUp || $needsReply,
            ];
        })->values();
    }

    /**
     * @return Collection<int, string>
     */
    private static function phones(string $search): Collection
    {
        $query = SmsMessage::query()->where('direction', SmsDirection::Inbound)->select('contact_phone')->distinct();

        $search = trim($search);

        if ($search !== '') {
            // "0412…" is how people write +61412…, so look for the digits after the leading zero.
            $digits = ltrim((string) preg_replace('/\D/', '', $search), '0');
            $names = Subscriber::query()->where('first_name', 'ilike', "%{$search}%")->select('phone');

            $query->where(fn ($query) => $query
                ->whereIn('contact_phone', $names)
                ->when($digits !== '', fn ($query) => $query->orWhere('contact_phone', 'like', "%{$digits}%")));
        }

        return $query->get()->map(fn (SmsMessage $message): string => $message->contact_phone)->values();
    }
}
