<?php

declare(strict_types=1);

namespace App\Sms;

use App\Enums\DeliveryMethod;
use App\Models\Order;

/**
 * The text that tells a customer their order is in and when it arrives or can be collected. One text covers all of
 * someone's orders that go out the same way, so a customer who checked out twice hears once.
 *
 * It's kept to one text (160 characters): the link to the first order's page (see Order::shortLink()) carries the
 * rest, from what they ordered to the farm's address and map and the other orders the text covers. A name too long
 * to fit, or one outside the GSM alphabet that would split the text, gives way to a plain "Thanks!".
 *
 * It's about their order, not marketing, so it names the shop but has no opt-out line.
 */
final readonly class OrderConfirmationText
{
    /**
     * Words people put before their name at checkout that don't belong in "Thanks …".
     */
    private const array TITLES = ['mr', 'mrs', 'ms', 'miss', 'mx', 'dr'];

    /**
     * @param  non-empty-list<Order>  $orders  one person's, all pickups or all deliveries, with drop loaded
     */
    public static function for(array $orders): SmsText
    {
        $name = self::firstName($orders[0]);
        $text = self::compose($orders, $name);

        return $name !== null && $text->segments() > 1 ? self::compose($orders, null) : $text;
    }

    /**
     * @param  non-empty-list<Order>  $orders
     */
    private static function compose(array $orders, ?string $name): SmsText
    {
        $first = $orders[0];
        $what = count($orders) > 1 ? 'your '.count($orders).' orders' : "order {$first->reference()}";

        $when = $first->delivery_method === DeliveryMethod::Delivery
            ? "We'll deliver {$what} on ".self::deliveryNight($orders).' after 4pm. Details:'
            : "Pick up {$what} ".($first->drop->deliveryWeekdays() ?? config()->string('shop.pickup.days')).', '
                .config()->string('shop.pickup.hours').'. Address & map:';

        return new SmsText(SmsText::SENDER.SmsText::tidy('Thanks'.($name === null ? '' : " {$name}")."! {$when} {$first->shortLink()}"));
    }

    /**
     * The name to thank them by: "Thanks Sam", skipping titles and initials ("Mrs D Hogenhout" has none to use), and
     * tamed if it was typed in capitals.
     */
    private static function firstName(Order $order): ?string
    {
        $words = preg_split('/\s+/', trim((string) $order->customer_name));
        $words = array_values(array_filter($words === false ? [] : $words, fn (string $word): bool => ! in_array(strtolower(rtrim($word, '.')), self::TITLES, true)));
        $name = $words[0] ?? '';

        // The customer typed this, and the text goes from the shop's number to whatever phone they gave, so only a
        // plain name is used: never a link or anything else someone could get the shop to text a stranger.
        if (preg_match("/^[\\p{L}'-]{2,20}$/u", $name) !== 1) {
            return null;
        }

        return mb_strtoupper($name) === $name ? ucfirst(mb_strtolower($name)) : $name;
    }

    /**
     * "Sunday" when every order is for that night, or the drop's nights when they said either suits.
     *
     * @param  non-empty-list<Order>  $orders
     */
    private static function deliveryNight(array $orders): string
    {
        $days = array_unique(array_map(fn (Order $order): ?string => $order->delivery_day?->format('l'), $orders));

        if (count($days) === 1 && $days[array_key_first($days)] !== null) {
            return (string) $days[array_key_first($days)];
        }

        return $orders[0]->drop->deliveryWeekdays() ?? config()->string('shop.pickup.days');
    }
}
