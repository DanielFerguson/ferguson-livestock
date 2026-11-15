<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Checkout\OrderPayments;
use App\Enums\OrderStatus;
use App\Models\Order;

/**
 * Hands orders over at pickup or on the doorstep: each is marked fulfilled, a cash order having its cash recorded
 * first. Every step is OrderPayments' compare-and-set on the status, so an order that changed since it was shown
 * (handed over from another phone, or a cash order cancelled) is left as it now is and given back. Callers pass
 * orders still to hand over (Paid or Cash owed); anything else comes back as not handed over.
 */
final readonly class HandOverOrders
{
    public function __construct(private OrderPayments $payments) {}

    /**
     * @param  iterable<Order>  $orders
     * @return list<Order> the orders that couldn't be handed over
     */
    public function __invoke(iterable $orders): array
    {
        $notHandedOver = [];

        foreach ($orders as $order) {
            if ($order->status === OrderStatus::CashOwed) {
                $this->payments->markCashReceived($order);
            }

            if (! $this->payments->markFulfilled($order)) {
                $notHandedOver[] = $order;
            }
        }

        return $notHandedOver;
    }
}
