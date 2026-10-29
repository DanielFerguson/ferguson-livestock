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
 * Settles an order whose hold has run out, so abandoned checkouts and lost webhooks never leave stock held. Stripe is
 * asked to close the payment page, in case it's still open, and its answer applied: paid, or expired with the stock back.
 */
final readonly class SettleClosedCheckout
{
    /**
     * How much longer than its hold the hourly sweep waits before it settles an order itself, in case the queued job
     * is still on its way.
     */
    private const int GRACE_MINUTES = 2;

    public function __construct(private PaymentGateway $payments, private OrderPayments $orders) {}

    /**
     * When an order can be settled: the moment its hold runs out (settling closes the payment page, so there's no
     * webhook to wait for), or a little after it was made if the request stopped before reaching Stripe.
     */
    public static function dueAt(Order $order): CarbonImmutable
    {
        return $order->stripe_checkout_session_id === null ? $order->created_at->addMinutes(self::GRACE_MINUTES) : $order->expires_at;
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
