<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Enums\SmsDirection;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SmsBroadcast;
use App\Models\SmsMessage;
use App\Models\StripeEvent;
use App\Models\Subscriber;
use App\Sms\TwilioErrors;
use App\Support\AustralianMobile;
use App\Support\DeliveryRun;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * Turns models into the compact arrays tools return, so every tool describes a drop (or a time) the same way.
 */
final class Presenter
{
    /**
     * An ISO-8601 time in shop time, so the model never has to convert from UTC.
     */
    public static function time(?CarbonInterface $time): ?string
    {
        return $time?->setTimezone(config()->string('shop.timezone'))->toIso8601String();
    }

    /**
     * @return array{cents: int, formatted: string}
     */
    public static function money(int $cents): array
    {
        return ['cents' => $cents, 'formatted' => Money::format($cents)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function drop(Drop $drop, bool $withItems = false): array
    {
        $drop->loadMissing('items.product.bundle.component');

        $report = $drop->preflight_report;

        $presented = [
            'id' => $drop->id,
            'name' => $drop->name,
            'status' => $drop->status()->value,
            'status_label' => $drop->status()->getLabel(),
            'published' => $drop->published_at !== null,
            'opens_at' => self::time($drop->opens_at),
            'closes_at' => self::time($drop->closes_at),
            'closed_by_hand_at' => self::time($drop->closed_at),
            'effective_closes_at' => self::time($drop->effectiveClosesAt()),
            'announced_at' => self::time($drop->announced_at),
            'delivery_days' => $drop->delivery_days,
            'preflight' => $report === null ? null : [
                'ran_at' => self::time($drop->preflight_ran_at),
                'passed' => $report['passed'],
                'problems' => $report['problems'],
            ],
        ];

        if ($withItems) {
            $presented['items'] = $drop->items->map(fn (DropItem $item): array => self::item($item))->values()->all();
        }

        return $presented;
    }

    /**
     * @return array<string, mixed>
     */
    public static function item(DropItem $item): array
    {
        return [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'product' => $item->product->name,
            'product_slug' => $item->product->slug,
            'product_type' => $item->product->type->value,
            'price' => self::money($item->price),
            'stripe_price_id' => $item->stripe_price_id,
            'has_own_stock' => $item->hasOwnStock(),
            'bundle' => $item->product->bundle === null ? null : ['of' => $item->product->bundle->component->name, 'units' => $item->product->bundle->units],
            'quantity' => $item->quantity,
            'available' => $item->available,
            'committed' => $item->hasOwnStock() ? $item->committed() : null,
            'max_per_order' => $item->max_per_order,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function order(Order $order, bool $detailed = false): array
    {
        $presented = [
            'reference' => $order->reference(),
            'id' => $order->id,
            'status' => $order->status->value,
            'status_label' => $order->status->getLabel(),
            'drop' => ['id' => $order->drop_id, 'name' => $order->drop->name],
            'customer' => $order->customer_name,
            'email' => $order->email,
            'phone' => $order->phone === null ? null : AustralianMobile::readable($order->phone),
            'delivery_method' => $order->delivery_method->value,
            'delivery_day' => $order->delivery_day?->toDateString(),
            'total' => self::money($order->total),
            'amount_refunded' => self::money($order->amount_refunded),
            'paid_at' => self::time($order->paid_at),
            'fulfilled_at' => self::time($order->fulfilled_at),
            'items' => DeliveryRun::items($order),
        ];

        if ($detailed) {
            $presented += [
                'shipping_address' => $order->shipping_address,
                'created_at' => self::time($order->created_at),
                'expires_at' => self::time($order->expires_at),
                'released_at' => self::time($order->released_at),
                'refunded_at' => self::time($order->refunded_at),
                'stripe_checkout_session_id' => $order->stripe_checkout_session_id,
                'stripe_payment_intent_id' => $order->stripe_payment_intent_id,
                'lines' => $order->items->map(fn (OrderItem $line): array => [
                    'product' => $line->product->name,
                    'quantity' => $line->quantity,
                    'unit_price' => self::money($line->unit_price),
                    'line_total' => self::money($line->lineTotal()),
                ])->values()->all(),
            ];
        }

        return $presented;
    }

    /**
     * @return array<string, mixed>
     */
    public static function subscriber(Subscriber $subscriber): array
    {
        return [
            'id' => $subscriber->id,
            'first_name' => $subscriber->first_name,
            'phone' => $subscriber->phone,
            'phone_readable' => AustralianMobile::readable($subscriber->phone),
            'postcode' => $subscriber->postcode,
            'subscribed' => $subscriber->isSubscribed(),
            'consented_at' => self::time($subscriber->consented_at),
            'consent_source' => $subscriber->consent_source,
            'unsubscribed_at' => self::time($subscriber->unsubscribed_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function broadcast(SmsBroadcast $broadcast): array
    {
        $counts = $broadcast->messages()->toBase()->pluck('status')->countBy();

        return [
            'id' => $broadcast->id,
            'status' => $broadcast->status()->value,
            'body' => $broadcast->body,
            'audience' => $broadcast->audienceLabel(),
            'drop_id' => $broadcast->drop_id,
            'scheduled_for' => self::time($broadcast->scheduled_for),
            'started_at' => self::time($broadcast->started_at),
            'finished_at' => self::time($broadcast->finished_at),
            'messages' => $counts->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function message(SmsMessage $message): array
    {
        return [
            'id' => $message->id,
            'direction' => $message->direction->value,
            'body' => $message->body,
            'status' => $message->status->value,
            'at' => self::time($message->created_at),
            'read' => $message->direction === SmsDirection::Outbound ? null : $message->read_at !== null,
            'from_broadcast' => $message->sms_broadcast_id,
            'error' => TwilioErrors::explain($message->error_code),
        ];
    }

    /**
     * @param  array{phone: string, name: ?string, last: SmsMessage, unread: int, optedOut: bool, followUp: bool, needsReply: bool, attention: bool}  $summary
     * @return array<string, mixed>
     */
    public static function conversation(array $summary): array
    {
        return [
            'phone' => $summary['phone'],
            'phone_readable' => AustralianMobile::readable($summary['phone']),
            'name' => $summary['name'],
            'last_message' => self::message($summary['last']),
            'unread' => $summary['unread'],
            'needs_reply' => $summary['needsReply'],
            'flagged_for_follow_up' => $summary['followUp'],
            'opted_out' => $summary['optedOut'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function stripeEvent(StripeEvent $event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->type,
            'received_at' => self::time($event->received_at),
            'processed_at' => self::time($event->processed_at),
            'attempts' => $event->attempts,
            'last_error' => $event->last_error,
        ];
    }
}
