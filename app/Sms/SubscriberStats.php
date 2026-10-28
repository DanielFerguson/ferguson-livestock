<?php

declare(strict_types=1);

namespace App\Sms;

use App\Models\Subscriber;

/**
 * How the text list is doing, for the MCP server and anything else that wants the numbers.
 */
final class SubscriberStats
{
    private const int WEEKS = 12;

    /**
     * @return array{
     *     subscribed: int,
     *     opted_out: int,
     *     total_ever: int,
     *     top_postcodes: array<int, array{postcode: string, subscribers: int}>,
     *     signups_per_week: list<array{week_starting: string, signups: int}>,
     *     consent_sources: array<array-key, int>,
     * }
     */
    public static function summary(): array
    {
        $timezone = config()->string('shop.timezone');

        $weeks = Subscriber::query()
            ->where('consented_at', '>=', now()->setTimezone($timezone)->startOfWeek()->subWeeks(self::WEEKS - 1))
            ->get(['consented_at'])
            ->countBy(fn (Subscriber $subscriber): string => $subscriber->consented_at->setTimezone($timezone)->startOfWeek()->toDateString());

        $signups = [];

        for ($week = self::WEEKS - 1; $week >= 0; $week--) {
            $start = now()->setTimezone($timezone)->startOfWeek()->subWeeks($week)->toDateString();
            $signups[] = ['week_starting' => $start, 'signups' => (int) ($weeks[$start] ?? 0)];
        }

        return [
            'subscribed' => Subscriber::query()->subscribed()->count(),
            'opted_out' => Subscriber::query()->whereNotNull('unsubscribed_at')->count(),
            'total_ever' => Subscriber::query()->count(),
            'top_postcodes' => Subscriber::query()->subscribed()->whereNotNull('postcode')->pluck('postcode')->countBy()
                ->map(fn (int $subscribers, int|string $postcode): array => ['postcode' => (string) $postcode, 'subscribers' => $subscribers])
                ->sortBy([['subscribers', 'desc'], ['postcode', 'asc']])
                ->take(10)
                ->values()
                ->all(),
            'signups_per_week' => $signups,
            'consent_sources' => Subscriber::query()->subscribed()->pluck('consent_source')->countBy()->map(fn (int $total): int => $total)->all(),
        ];
    }
}
