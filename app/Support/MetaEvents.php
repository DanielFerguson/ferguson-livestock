<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ProductType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Subscriber;

/**
 * Builds the events the shop reports to Meta's Conversions API, in Meta's format.
 *
 * Customer details are normalised and hashed (SHA-256) here, as Meta requires, so the queued job carrying an event
 * never holds them in plain text. The browser details (address, browser, Meta's cookies) are sent as they are.
 *
 * @phpstan-type MetaEvent array{event_name: string, event_time: int, event_id: string, action_source: string, event_source_url: string, user_data: array<string, string>, custom_data?: array<string, mixed>}
 */
final class MetaEvents
{
    private const string CURRENCY = 'AUD';

    private const string COUNTRY = 'au';

    /**
     * Someone joined the wait list. The thank-you page sends the same event from the browser with the same ID, and
     * Meta counts the two once.
     *
     * @return MetaEvent
     */
    public static function lead(Subscriber $subscriber, MetaVisitor $visitor, string $eventId, string $pageUrl): array
    {
        return [
            'event_name' => 'Lead',
            'event_time' => now()->getTimestamp(),
            'event_id' => $eventId,
            'action_source' => 'website',
            'event_source_url' => $pageUrl,
            'user_data' => self::hashed([
                'ph' => self::phone($subscriber->phone),
                'fn' => self::name($subscriber->first_name),
                'zp' => self::postcode($subscriber->postcode),
                'country' => self::COUNTRY,
            ]) + $visitor->userData(),
        ];
    }

    /**
     * A customer was handed to Stripe's payment page. Stripe hasn't told the shop who they are yet, so only their
     * browser identifies them.
     *
     * @return MetaEvent
     */
    public static function initiateCheckout(Order $order, MetaVisitor $visitor): array
    {
        return [
            'event_name' => 'InitiateCheckout',
            'event_time' => now()->getTimestamp(),
            'event_id' => "checkout-{$order->public_id}",
            'action_source' => 'website',
            'event_source_url' => route('order'),
            'user_data' => $visitor->userData(),
            'custom_data' => self::basket($order),
        ];
    }

    /**
     * Stripe confirmed an order's payment. Sent from the server, so it counts even if the customer never gets back
     * to the confirmation page.
     *
     * @return MetaEvent
     */
    public static function purchase(Order $order): array
    {
        $names = preg_split('/\s+/', trim((string) $order->customer_name), -1, PREG_SPLIT_NO_EMPTY);
        $names = $names === false ? [] : $names;
        $address = $order->shipping_address ?? [];

        return [
            'event_name' => 'Purchase',
            'event_time' => ($order->paid_at ?? now())->getTimestamp(),
            'event_id' => "purchase-{$order->public_id}",
            'action_source' => 'website',
            'event_source_url' => route('order'),
            'user_data' => self::hashed([
                'em' => $order->email === null ? null : mb_strtolower(trim($order->email)),
                'ph' => self::phone($order->phone),
                'fn' => self::name($names[0] ?? null),
                'ln' => count($names) > 1 ? self::name(end($names)) : null,
                'ct' => self::squashed($address['city'] ?? null),
                'st' => self::squashed($address['state'] ?? null),
                'zp' => self::postcode($address['postal_code'] ?? null),
                'country' => self::COUNTRY,
            ]) + MetaVisitor::fromArray($order->meta_visitor)->userData(),
            'custom_data' => [...self::basket($order), 'order_id' => $order->reference()],
        ];
    }

    /**
     * @return array{value: float, currency: string, content_type: string, contents: list<array{id: string, quantity: int}>, num_items: int}
     */
    private static function basket(Order $order): array
    {
        // The delivery fee counts towards the value, but isn't something the customer chose to buy.
        $items = $order->loadMissing('items.product')->items->reject(fn (OrderItem $item): bool => $item->product->type === ProductType::Delivery);

        $contents = array_values($items->map(fn (OrderItem $item): array => ['id' => (string) $item->product_id, 'quantity' => $item->quantity])->all());

        return [
            'value' => round($order->total / 100, 2),
            'currency' => self::CURRENCY,
            'content_type' => 'product',
            'contents' => $contents,
            'num_items' => array_sum(array_column($contents, 'quantity')),
        ];
    }

    /**
     * @param  array<string, string|null>  $fields
     * @return array<string, string>
     */
    private static function hashed(array $fields): array
    {
        return array_map(
            fn (string $value): string => hash('sha256', $value),
            array_filter($fields, fn (?string $value): bool => $value !== null && $value !== ''),
        );
    }

    /**
     * Digits only, with the country code and without the leading zero: 0412 345 678 and +61 412 345 678 are both
     * 61412345678.
     */
    private static function phone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            return '61'.substr($digits, 1);
        }

        return $digits === '' ? null : $digits;
    }

    private static function name(?string $name): ?string
    {
        return $name === null ? null : mb_strtolower(trim($name));
    }

    private static function postcode(?string $postcode): ?string
    {
        return $postcode === null ? null : preg_replace('/\s/', '', mb_strtolower($postcode));
    }

    /**
     * Lowercase with nothing but letters and digits, as Meta wants cities and states: "Snake Valley" is "snakevalley".
     */
    private static function squashed(?string $value): ?string
    {
        return $value === null ? null : preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($value));
    }
}
