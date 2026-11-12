<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Drop;
use App\Models\Order;
use App\Models\Subscriber;
use Illuminate\Database\Eloquent\Builder;

/**
 * What Meta ads have brought in, by the shop's own records rather than Meta's.
 *
 * Meta only credits a sale to an ad within 7 days of the click, but people usually join the wait list from an ad and
 * buy at a drop weeks or months later. So the shop keeps the ad's UTM tags on each sign-up and order itself, and
 * matches orders to sign-ups by mobile number.
 */
final class MetaAdResults
{
    /**
     * @return array{sign_ups: int, sign_ups_last_30_days: int}
     */
    public static function signUps(): array
    {
        return [
            'sign_ups' => self::adSubscribers()->count(),
            'sign_ups_last_30_days' => self::adSubscribers()->where('created_at', '>=', now()->subDays(30))->count(),
        ];
    }

    /**
     * A drop's paid orders from people Meta ads brought in: those who joined the wait list from an ad, and those who
     * came straight from one to order.
     *
     * @return array{sign_ups_before_drop: int, of_whom_bought: int, orders: int, revenue: int}
     */
    public static function forDrop(Drop $drop): array
    {
        $orders = Order::query()
            ->where('drop_id', $drop->id)
            ->whereIn('status', [OrderStatus::Paid, OrderStatus::Fulfilled])
            // A friend paying cash isn't a sale an ad made, even if they once joined the wait list from one.
            ->where('payment_method', PaymentMethod::Stripe)
            ->where(fn (Builder $query) => $query
                ->where('utm_source', AdCampaign::META)
                ->orWhereIn('phone', self::adSubscribers()->select('phone')))
            ->get(['phone', 'total', 'amount_refunded']);

        $joinedBefore = self::adSubscribers()->where('created_at', '<', $drop->opens_at);

        return [
            'sign_ups_before_drop' => $joinedBefore->count(),
            'of_whom_bought' => (clone $joinedBefore)->whereIn('phone', $orders->pluck('phone')->filter()->unique()->values())->count(),
            'orders' => $orders->count(),
            'revenue' => array_sum($orders->map(fn (Order $order): int => $order->total - $order->amount_refunded)->all()),
        ];
    }

    /**
     * @return Builder<Subscriber>
     */
    private static function adSubscribers(): Builder
    {
        return Subscriber::query()->where('utm_source', AdCampaign::META);
    }
}
