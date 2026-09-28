<?php

namespace App\Checkout;

use App\Enums\OrderStatus;
use App\Mail\NewOrderNotification;
use App\Mail\OrderConfirmation;
use App\Models\Order;
use App\Payments\CheckoutSession;
use App\Stock\StockLedger;
use Illuminate\Support\Facades\Mail;

/**
 * Moves an order along as its payment progresses.
 *
 * Every change is a compare-and-set on the order's status, so Stripe's retried webhooks, the sweep and the
 * confirmation page can all report the same news without stock moving twice or emails going out twice.
 */
final readonly class OrderPayments
{
    public function __construct(private StockLedger $stock) {}

    /**
     * Bring the order in line with its Checkout Session.
     */
    public function apply(Order $order, CheckoutSession $session): void
    {
        match (true) {
            $session->isPaid() => $this->markPaid($order, $session),
            $session->isAwaitingPayment() => $this->markProcessing($order, $session),
            $session->isExpired() => $this->markExpired($order),
            default => null,
        };
    }

    public function markPaid(Order $order, CheckoutSession $session): bool
    {
        if (! $this->transition($order, [OrderStatus::Pending, OrderStatus::Processing], OrderStatus::Paid)) {
            return false;
        }

        $order->fill([...$this->buyerDetails($session), 'paid_at' => now()])->save();

        if ($order->email !== null) {
            Mail::to($order->email)->queue(new OrderConfirmation($order));
        }

        Mail::to(config()->string('shop.email'))->queue(new NewOrderNotification($order));

        return true;
    }

    /**
     * A bank debit: the customer finished checkout, but the money takes days to arrive. The stock stays held.
     */
    public function markProcessing(Order $order, CheckoutSession $session): bool
    {
        if (! $this->transition($order, [OrderStatus::Pending], OrderStatus::Processing)) {
            return false;
        }

        $order->fill($this->buyerDetails($session))->save();

        return true;
    }

    public function markExpired(Order $order): bool
    {
        if (! $this->transition($order, [OrderStatus::Pending], OrderStatus::Expired)) {
            return false;
        }

        $this->stock->release($order);

        return true;
    }

    public function markFailed(Order $order): bool
    {
        if (! $this->transition($order, [OrderStatus::Pending, OrderStatus::Processing], OrderStatus::Failed)) {
            return false;
        }

        $this->stock->release($order);

        return true;
    }

    public function markFulfilled(Order $order): bool
    {
        if (! $this->transition($order, [OrderStatus::Paid], OrderStatus::Fulfilled)) {
            return false;
        }

        $order->update(['fulfilled_at' => now()]);

        return true;
    }

    /**
     * Stripe reports the total refunded so far, so recording it again changes nothing. Refunds never restock:
     * the farm adjusts stock by hand if it wants to sell the items again.
     */
    public function recordRefund(Order $order, int $amountRefunded): void
    {
        $order->refresh();

        if ($amountRefunded <= $order->amount_refunded) {
            return;
        }

        $order->update(['amount_refunded' => min($amountRefunded, $order->total), 'refunded_at' => $order->refunded_at ?? now()]);
    }

    /**
     * @param  list<OrderStatus>  $from
     */
    private function transition(Order $order, array $from, OrderStatus $to): bool
    {
        $changed = Order::query()
            ->whereKey($order->id)
            ->whereIn('status', $from)
            ->update(['status' => $to, 'updated_at' => now()]) === 1;

        $order->refresh();

        return $changed;
    }

    /**
     * @return array<string, mixed>
     */
    private function buyerDetails(CheckoutSession $session): array
    {
        return array_filter([
            'customer_name' => $session->customerName,
            'email' => $session->email,
            'phone' => $session->phone,
            'shipping_address' => $session->shippingAddress,
            'stripe_payment_intent_id' => $session->paymentIntentId,
        ], fn (mixed $value): bool => $value !== null);
    }
}
