<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Checkout\OrderPayments;
use App\Models\Order;
use App\Payments\CheckoutSession;
use Closure;

/**
 * A change to an order's payment, run in its own process by the concurrency tests so changes can race each other.
 */
final class ConcurrentSettle
{
    /**
     * @return Closure(): string 'expired' or 'unchanged'
     */
    public static function expires(int $orderId, float $startAt): Closure
    {
        return static function () use ($orderId, $startAt): string {
            time_sleep_until($startAt);

            return app(OrderPayments::class)->markExpired(Order::query()->findOrFail($orderId)) ? 'expired' : 'unchanged';
        };
    }

    /**
     * @return Closure(): string 'paid' or 'unchanged'
     */
    public static function pays(int $orderId, float $startAt): Closure
    {
        return static function () use ($orderId, $startAt): string {
            time_sleep_until($startAt);
            $order = Order::query()->findOrFail($orderId);
            $session = new CheckoutSession((string) $order->stripe_checkout_session_id, 'complete', 'paid', paymentIntentId: 'pi_race', customerName: 'Sam Buyer', email: 'buyer@example.test', phone: '+61400000002');

            return app(OrderPayments::class)->markPaid($order, $session) ? 'paid' : 'unchanged';
        };
    }
}
