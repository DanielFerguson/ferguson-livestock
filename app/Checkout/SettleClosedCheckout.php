<?php

declare(strict_types=1);

namespace App\Checkout;

use App\Enums\OrderStatus;
use App\Exceptions\PaymentProviderUnavailable;
use App\Models\Order;
use App\Payments\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Settles an order whose payment page has closed, so a lost webhook never leaves its stock held. Stripe is asked
 * to close the page, in case it's still open, and its answer applied: paid, or expired with the stock back.
 */
final readonly class SettleClosedCheckout
{
    /**
     * Stripe closes a payment page at its expiry time; this leaves a little longer for its webhook to arrive.
     */
    private const int GRACE_MINUTES = 2;

    public function __construct(private PaymentGateway $payments, private OrderPayments $orders) {}

    /**
     * When an order can be settled: a little after its payment page closes, or after it was made if the request
     * stopped before reaching Stripe.
     */
    public static function dueAt(Order $order): CarbonImmutable
    {
        $closedAt = $order->stripe_checkout_session_id === null ? $order->created_at : $order->expires_at;

        return $closedAt->addMinutes(self::GRACE_MINUTES);
    }

    /**
     * Pending orders that are due to be settled.
     *
     * @return Builder<Order>
     */
    public static function due(): Builder
    {
        $cutOff = now()->subMinutes(self::GRACE_MINUTES);

        return Order::query()
            ->where('status', OrderStatus::Pending)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->whereNotNull('stripe_checkout_session_id')->where('expires_at', '<=', $cutOff))
                // Holding stock but never reached Stripe: the request stopped between the two.
                ->orWhere(fn (Builder $query) => $query->whereNull('stripe_checkout_session_id')->where('created_at', '<=', $cutOff)));
    }

    /**
     * @throws PaymentProviderUnavailable
     */
    public function __invoke(Order $order): void
    {
        if ($order->stripe_checkout_session_id === null) {
            $this->orders->markExpired($order);

            return;
        }

        $this->orders->apply($order, $this->payments->expireCheckoutSession($order->stripe_checkout_session_id));
    }
}
