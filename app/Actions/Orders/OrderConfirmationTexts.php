<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Jobs\SendOrderConfirmationText;
use App\Models\Drop;
use App\Models\Order;
use App\Models\Subscriber;
use App\Sms\OrderConfirmationText;
use App\Support\AustralianMobile;

/**
 * A drop's order texts, sent together by the owner: everyone with an order still to collect or be delivered who
 * hasn't been texted about it. Orders paid from now on are texted as they're paid (see OrderPayments).
 */
final readonly class OrderConfirmationTexts
{
    /**
     * Who would be texted what, and whose orders are left out and why. Nothing is sent.
     *
     * @return array{texts: list<array{phone: string, name: string|null, orders: list<string>, order_ids: list<int>, text: string, segments: int}>, skipped: list<array{order: string, name: string|null, reason: string}>}
     */
    public function preview(Drop $drop): array
    {
        $orders = Order::query()
            ->with(['items.product', 'drop'])
            ->whereBelongsTo($drop)
            ->whereIn('status', [OrderStatus::Paid, OrderStatus::CashOwed, OrderStatus::Fulfilled])
            ->orderBy('id')
            ->get();

        $optedOut = Subscriber::query()->whereNotNull('unsubscribed_at')->pluck('phone')->all();
        $groups = [];
        $skipped = [];

        foreach ($orders as $order) {
            $phone = AustralianMobile::toE164((string) $order->phone);

            $reason = match (true) {
                $order->status === OrderStatus::Fulfilled => 'Already collected or delivered',
                $order->confirmation_texted_at !== null => 'Already texted',
                $phone === null => 'No Australian mobile number',
                in_array($phone, $optedOut, true) => 'Has opted out of texts',
                default => null,
            };

            if ($reason !== null || $phone === null) {
                $skipped[] = ['order' => $order->reference(), 'name' => $order->customer_name, 'reason' => (string) $reason];

                continue;
            }

            $groups["{$phone}|{$order->delivery_method->value}"][] = $order;
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

        return ['texts' => $texts, 'skipped' => $skipped];
    }

    /**
     * Queue everyone's text from the preview, straight away even in quiet hours: the owner has just chosen to send.
     *
     * @return int how many people are being texted
     */
    public function send(Drop $drop): int
    {
        $texts = $this->preview($drop)['texts'];

        foreach ($texts as $text) {
            SendOrderConfirmationText::dispatch($text['order_ids'], evenInQuietHours: true);
        }

        return count($texts);
    }
}
