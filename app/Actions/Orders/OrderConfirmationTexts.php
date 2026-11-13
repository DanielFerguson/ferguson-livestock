<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\SmsDirection;
use App\Enums\SmsStatus;
use App\Jobs\SendOrderConfirmationText;
use App\Models\Drop;
use App\Models\Order;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use App\Sms\OrderConfirmationText;
use App\Sms\SmsText;
use App\Support\AustralianMobile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The texts that tell customers what they ordered and when it's ready (see OrderConfirmationText).
 *
 * A text is saved as soon as it's due, waiting to go, so the admin can see it before it's sent: an order paid in quiet
 * hours waits there until 8am. Each order is linked to its text when the text is saved, so nobody is texted twice.
 */
final readonly class OrderConfirmationTexts
{
    /**
     * Save and send the text for an order that's just been paid. In quiet hours it waits until 8am.
     */
    public function forPaidOrder(Order $order): ?SmsMessage
    {
        $message = $this->queue([$order->loadMissing(['items.product', 'drop'])]);

        if ($message instanceof SmsMessage) {
            SendOrderConfirmationText::dispatch($message->id)->afterCommit();
        }

        return $message;
    }

    /**
     * A drop's texts as they stand: new ones for orders not yet texted (one per person, covering all their orders),
     * texts waiting for the morning, and orders left out with the reason. Nothing is saved or sent.
     *
     * @return array{texts: list<array{phone: string, name: string|null, orders: list<string>, order_ids: list<int>, text: string, segments: int}>, waiting: list<array{message_id: int, phone: string, name: string|null, orders: list<string>, text: string, segments: int}>, skipped: list<array{order: string, name: string|null, reason: string}>}
     */
    public function preview(Drop $drop): array
    {
        $orders = Order::query()
            ->with(['items.product', 'drop', 'confirmationText'])
            ->whereBelongsTo($drop)
            ->whereIn('status', [OrderStatus::Paid, OrderStatus::CashOwed, OrderStatus::Fulfilled])
            ->orderBy('id')
            ->get();

        $optedOut = Subscriber::query()->whereNotNull('unsubscribed_at')->pluck('phone')->all();
        $groups = [];
        $skipped = [];

        foreach ($orders as $order) {
            $phone = AustralianMobile::toE164((string) $order->phone);
            $text = $order->confirmationText;

            $reason = match (true) {
                $text instanceof SmsMessage && $text->status === SmsStatus::Queued => null,
                $text instanceof SmsMessage => 'Already texted',
                $order->status === OrderStatus::Fulfilled => 'Already collected or delivered',
                $phone === null => 'No Australian mobile number',
                in_array($phone, $optedOut, true) => 'Has opted out of texts',
                default => null,
            };

            if ($reason !== null) {
                $skipped[] = ['order' => $order->reference(), 'name' => $order->customer_name, 'reason' => $reason];
            } elseif (! $text instanceof SmsMessage && $phone !== null) {
                $groups["{$phone}|{$order->delivery_method->value}"][] = $order;
            }
        }

        $texts = [];

        foreach ($groups as $key => $group) {
            $text = OrderConfirmationText::for($group);

            $texts[] = [
                'phone' => explode('|', $key)[0],
                'name' => $group[0]->customer_name,
                'orders' => array_map(fn (Order $order): string => $order->reference(), $group),
                'order_ids' => array_map(fn (Order $order): int => $order->id, $group),
                'text' => $text->text,
                'segments' => $text->segments(),
            ];
        }

        return ['texts' => $texts, 'waiting' => $this->waiting($drop), 'skipped' => $skipped];
    }

    /**
     * Send a drop's texts now, even in quiet hours: the new ones, and any waiting for the morning. The owner has just
     * chosen to send.
     *
     * @return int how many texts are going out
     */
    public function sendNow(Drop $drop): int
    {
        $preview = $this->preview($drop);
        $messageIds = array_column($preview['waiting'], 'message_id');

        foreach ($preview['texts'] as $text) {
            $orders = Order::query()->with(['items.product', 'drop'])->whereKey($text['order_ids'])->orderBy('id')->get()->all();
            $message = $orders === [] ? null : $this->queue(array_values($orders));

            if ($message instanceof SmsMessage) {
                $messageIds[] = $message->id;
            }
        }

        foreach ($messageIds as $messageId) {
            SendOrderConfirmationText::dispatch($messageId, evenInQuietHours: true);
        }

        return count($messageIds);
    }

    /**
     * Send one waiting text now, even in quiet hours.
     */
    public function sendWaitingNow(SmsMessage $message): bool
    {
        if (! $message->is_order_confirmation || $message->status !== SmsStatus::Queued) {
            return false;
        }

        SendOrderConfirmationText::dispatch($message->id, evenInQuietHours: true);

        return true;
    }

    /**
     * Save the text for one person's orders, waiting to go, and link the orders to it. Orders already given a text
     * are left out, so two requests at once can't both save one. Null when there's nothing to text, the number isn't an
     * Australian mobile, or it has opted out.
     *
     * @param  non-empty-list<Order>  $orders  one person's, all going out the same way, with items.product and drop loaded
     */
    private function queue(array $orders): ?SmsMessage
    {
        $phone = AustralianMobile::toE164((string) $orders[0]->phone);
        $subscriber = $phone === null ? null : Subscriber::query()->firstWhere('phone', $phone);

        if ($phone === null || ($subscriber instanceof Subscriber && ! $subscriber->isSubscribed())) {
            return null;
        }

        return DB::transaction(function () use ($orders, $phone, $subscriber): ?SmsMessage {
            /** @var list<int> $free */
            $free = Order::query()
                ->whereKey(array_map(fn (Order $order): int => $order->id, $orders))
                ->whereNull('confirmation_sms_message_id')
                ->whereIn('status', [OrderStatus::Paid, OrderStatus::CashOwed])
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            $toText = array_values(array_filter($orders, fn (Order $order): bool => in_array($order->id, $free, true)));

            if ($toText === []) {
                return null;
            }

            $message = SmsMessage::query()->create([
                'subscriber_id' => $subscriber?->id,
                'direction' => SmsDirection::Outbound,
                'to' => $phone,
                'from' => config()->string('services.twilio.from'),
                'body' => OrderConfirmationText::for($toText)->text,
                'status' => SmsStatus::Queued,
                'is_order_confirmation' => true,
            ]);

            Order::query()->whereKey($free)->update(['confirmation_sms_message_id' => $message->id]);

            return $message;
        });
    }

    /**
     * @return list<array{message_id: int, phone: string, name: string|null, orders: list<string>, text: string, segments: int}>
     */
    private function waiting(Drop $drop): array
    {
        /** @var Collection<int, SmsMessage> $messages */
        $messages = SmsMessage::query()
            ->with('orders')
            ->where('is_order_confirmation', true)
            ->where('status', SmsStatus::Queued)
            ->whereHas('orders', fn ($orders) => $orders->whereBelongsTo($drop))
            ->orderBy('id')
            ->get();

        return array_values($messages->map(fn (SmsMessage $message): array => [
            'message_id' => $message->id,
            'phone' => $message->to,
            'name' => $message->orders->first()?->customer_name,
            'orders' => array_values($message->orders->map(fn (Order $order): string => $order->reference())->all()),
            'text' => $message->body,
            'segments' => (new SmsText($message->body))->segments(),
        ])->all());
    }
}
