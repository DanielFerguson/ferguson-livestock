<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Exceptions\CannotChangeOrder;
use App\Mail\DeliveryNightChanged;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * Moves a delivery to another of its drop's nights, or to "either night", from the customer's order page.
 *
 * A change closes at 12pm (shop time) the day before the earlier of the two nights, so the owner can plan the run;
 * "either night" counts as the drop's first. Switching to pickup or changing the address moves money or the run, so
 * customers ask the owner for those. Each change emails the farm and rewrites the order text if it's still waiting.
 */
final readonly class ChangeDeliveryNight
{
    /**
     * What the order page sends for "either night suits me".
     */
    public const string EITHER = 'either';

    /**
     * Orders still to deliver.
     *
     * @var list<OrderStatus>
     */
    private const array CHANGEABLE = [OrderStatus::Paid, OrderStatus::Processing, OrderStatus::CashOwed];

    public function __construct(private OrderConfirmationTexts $texts) {}

    /**
     * The nights the order can move to now, as the page offers them: dates (Y-m-d), and "either" when the drop has more
     * than one night. Empty for a pickup, a delivered or fully refunded order, a one-night drop, or once it's too late.
     *
     * @return list<string>
     */
    public function choicesFor(Order $order): array
    {
        $nights = $order->drop->delivery_days;

        if ($order->delivery_method !== DeliveryMethod::Delivery || ! in_array($order->status, self::CHANGEABLE, true) || $order->isRefundedInFull() || count($nights) < 2) {
            return [];
        }

        $current = self::nightOf($order);

        return array_values(array_filter(
            [...$nights, self::EITHER],
            fn (string $night): bool => $night !== $current && now()->lt(self::closesAt($nights, $current, $night)),
        ));
    }

    /**
     * @param  Order  $order  with its drop loaded
     * @param  string  $night  one of the drop's delivery days (Y-m-d), or "either"
     *
     * @throws CannotChangeOrder
     */
    public function __invoke(Order $order, string $night): Order
    {
        if ($order->delivery_method !== DeliveryMethod::Delivery) {
            throw new CannotChangeOrder('This order is a farm pickup, so there’s no delivery night to change.');
        }

        if ($order->isRefundedInFull()) {
            throw new CannotChangeOrder('This order has been refunded, so there’s no delivery night to change.');
        }

        if ($night === self::nightOf($order)) {
            return $order;
        }

        if (! in_array($night, [...$order->drop->delivery_days, self::EITHER], true)) {
            throw new CannotChangeOrder('That isn’t one of this drop’s delivery nights. Choose another.');
        }

        if (! in_array($night, $this->choicesFor($order), true)) {
            throw new CannotChangeOrder('It’s too late to change your night here. Text us on '.config()->string('shop.phone.display').' and we’ll see what we can do.');
        }

        $previousNight = (string) $order->deliveryNight();
        $order->update(['delivery_day' => $night === self::EITHER ? null : $night]);

        $this->texts->rewriteWaiting($order);
        Mail::to(config()->string('shop.email'))->queue(new DeliveryNightChanged($order, $previousNight));

        return $order;
    }

    /**
     * The order's night as the page sends it.
     */
    private static function nightOf(Order $order): string
    {
        return $order->delivery_day?->toDateString() ?? self::EITHER;
    }

    /**
     * 12pm, shop time, the day before the earlier of two nights. "Either" counts as the drop's first night.
     *
     * @param  non-empty-list<string>  $nights  the drop's delivery days
     */
    private static function closesAt(array $nights, string $from, string $to): CarbonImmutable
    {
        $first = min($nights);
        $earlier = min($from === self::EITHER ? $first : $from, $to === self::EITHER ? $first : $to);

        return CarbonImmutable::parse($earlier, config()->string('shop.timezone'))->subDay()->setTime(12, 0);
    }
}
