<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Notifications\AdminPush;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Lets a pickup customer tell the farm they've arrived, from their order page, so Dan or Tahlia can come out and meet
 * them. It pushes to the admin's phone, so it's only offered on the drop's pickup days, and only while a phone has
 * pushes turned on: nobody is left waiting at the house for a push that goes nowhere.
 */
final readonly class AnnounceArrival
{
    /**
     * Orders still to collect.
     *
     * @var list<OrderStatus>
     */
    private const array COLLECTABLE = [OrderStatus::Paid, OrderStatus::Processing, OrderStatus::CashOwed];

    /**
     * How long after a push another tap counts as the same arrival, so a second tap doesn't push again.
     */
    private const int SAME_ARRIVAL_SECONDS = 120;

    /**
     * @param  Order  $order  with its drop loaded
     */
    public function isOpenFor(Order $order): bool
    {
        return $order->delivery_method === DeliveryMethod::Pickup
            && in_array($order->status, self::COLLECTABLE, true)
            && ! $order->isRefundedInFull()
            && in_array(now(config()->string('shop.timezone'))->toDateString(), $order->drop->delivery_days, true)
            && User::admin()?->pushSubscriptions()->exists() === true;
    }

    /**
     * Push the admin's phone that the customer is here. Call it only when isOpenFor() the order.
     *
     * @return bool whether the farm was pushed just now: false when this arrival already was
     */
    public function __invoke(Order $order): bool
    {
        // Counted as it's taken, in one atomic step, so taps sent at once can't each push.
        if (RateLimiter::hit("order-arrived:{$order->id}", self::SAME_ARRIVAL_SECONDS) > 1) {
            return false;
        }

        User::admin()?->notify(AdminPush::forArrival($order));

        return true;
    }
}
