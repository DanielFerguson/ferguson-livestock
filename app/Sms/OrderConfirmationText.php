<?php

declare(strict_types=1);

namespace App\Sms;

use App\Enums\DeliveryMethod;
use App\Enums\ProductType;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Arr;

/**
 * The text that tells a customer what they ordered and when it arrives or can be collected. One text covers all of
 * someone's orders that go out the same way, so a customer who checked out twice hears once.
 *
 * It's about their order, not marketing, so it names the shop but has no opt-out line. It keeps to the GSM
 * alphabet, so each part holds 153 characters rather than 67.
 */
final readonly class OrderConfirmationText
{
    /**
     * Words people put before their name at checkout that don't belong in "Hi …".
     */
    private const array TITLES = ['mr', 'mrs', 'ms', 'miss', 'mx', 'dr'];

    /**
     * @param  non-empty-list<Order>  $orders  one person's, all pickups or all deliveries, with items.product and drop loaded
     */
    public static function for(array $orders): SmsText
    {
        $first = $orders[0];
        $name = self::firstName($first);
        $references = Arr::join(array_map(fn (Order $order): string => $order->reference(), $orders), ', ', ' and ');
        [$items, $units] = self::items($orders);
        $it = $units > 1 ? 'them' : 'it';

        $when = $first->delivery_method === DeliveryMethod::Delivery
            ? "We'll deliver {$it} after 4pm on ".self::deliveryNight($orders).", and we'll text you closer to the time."
            : 'Pick '.$it.' up from '.config()->string('shop.location.street').', '.config()->string('shop.location.locality').', '
                .($first->drop->deliveryWeekdays() ?? config()->string('shop.pickup.days')).' between '
                .str_replace(' to ', ' and ', config()->string('shop.pickup.hours')).'. Map: '.self::mapLink();

        $body = 'Hi'.($name === null ? ' there' : " {$name}").', thanks for your order'.(count($orders) > 1 ? 's' : '')
            ." {$references}: {$items}. {$when} Questions? Just reply here. - ".self::signOff();

        return new SmsText(SmsText::SENDER.SmsText::tidy($body));
    }

    /**
     * The name to greet them by: "Hi Sam", skipping titles and initials ("Mrs D Hogenhout" has none to use), and
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
     * "1 x 10kg Beef Box, 2 x 500g Beef Mince", with the same product across orders added up, and how many units that
     * is. It's what they ordered, so the delivery fee and anything given free are left out.
     *
     * @param  non-empty-list<Order>  $orders
     * @return array{string, int}
     */
    private static function items(array $orders): array
    {
        $quantities = [];
        $names = [];

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                /** @var OrderItem $item */
                if ($item->product->type === ProductType::Delivery || $item->free) {
                    continue;
                }

                $quantities[$item->product_id] = ($quantities[$item->product_id] ?? 0) + $item->quantity;
                $names[$item->product_id] = self::plainName($item->product->name);
            }
        }

        $lines = array_map(fn (int $productId): string => "{$quantities[$productId]} x {$names[$productId]}", array_keys($quantities));

        return [implode(', ', $lines), array_sum($quantities)];
    }

    /**
     * A product's name without the shop's labels that don't belong in a text, like " · save $20", and with "×"
     * written as "x".
     */
    private static function plainName(string $name): string
    {
        return trim(str_replace('×', 'x', (string) preg_replace('/\s*·.*$/u', '', $name)));
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

    /**
     * fergusonlivestock.com.au/farm, which sends phones to the farm on Google Maps.
     */
    private static function mapLink(): string
    {
        return (string) preg_replace('#^https?://(www\.)?#', '', route('farm'));
    }

    /**
     * "Daniel & Tahlia".
     */
    private static function signOff(): string
    {
        /** @var list<string> $owners */
        $owners = config()->array('shop.owners');

        return implode(' & ', array_map(fn (string $owner): string => explode(' ', $owner)[0], $owners));
    }
}
