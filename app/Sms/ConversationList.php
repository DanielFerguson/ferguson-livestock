<?php

namespace App\Sms;

use App\Enums\SmsDirection;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use Illuminate\Support\Collection;

/**
 * The people who have texted the shop's number, and each conversation with them: what they sent, what was sent
 * to them (broadcasts included) and what was answered, grouped by their phone number.
 */
final class ConversationList
{
    private const int LIMIT = 100;

    /**
     * Everyone who has texted in, most recent conversation first.
     *
     * @return Collection<int, array{phone: string, name: ?string, last: SmsMessage, unread: int, optedOut: bool}>
     */
    public static function summaries(string $search = ''): Collection
    {
        $phones = self::phones($search);

        $latest = SmsMessage::query()
            ->whereIn('contact_phone', $phones)
            ->orderBy('contact_phone')->orderByDesc('created_at')->orderByDesc('id')
            ->selectRaw('distinct on (contact_phone) sms_messages.*')
            ->get()
            ->sortByDesc('created_at')
            ->take(self::LIMIT);

        $unread = SmsMessage::query()
            ->whereIn('contact_phone', $latest->pluck('contact_phone'))
            ->where('direction', SmsDirection::Inbound)->whereNull('read_at')
            ->selectRaw('contact_phone, count(*) as unread')->groupBy('contact_phone')
            ->pluck('unread', 'contact_phone');

        $subscribers = Subscriber::query()->whereIn('phone', $latest->pluck('contact_phone'))->get()->keyBy('phone');

        return $latest->map(fn (SmsMessage $last): array => [
            'phone' => $last->contact_phone,
            'name' => $subscribers->get($last->contact_phone)?->first_name,
            'last' => $last,
            'unread' => is_numeric($count = $unread->get($last->contact_phone)) ? (int) $count : 0,
            'optedOut' => $subscribers->get($last->contact_phone)?->isSubscribed() === false,
        ])->values();
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
     * How many people have texts waiting for you to read.
     */
    public static function unreadCount(): int
    {
        return SmsMessage::query()->where('direction', SmsDirection::Inbound)->whereNull('read_at')->distinct()->count('contact_phone');
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
