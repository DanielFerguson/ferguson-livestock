<?php

namespace App\Console\Commands;

use App\Checkout\OrderPayments;
use App\Enums\OrderStatus;
use App\Exceptions\PaymentProviderUnavailable;
use App\Models\Order;
use App\Payments\PaymentGateway;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class SweepOrdersCommand extends Command
{
    /**
     * Stripe closes a payment page at its expiry time; this leaves a little longer for its webhook to arrive.
     */
    private const int GRACE_MINUTES = 2;

    /**
     * @var string
     */
    protected $signature = 'orders:sweep';

    /**
     * @var string
     */
    protected $description = 'Settle orders whose payment page has closed, so a lost webhook never leaves stock held';

    public function handle(PaymentGateway $payments, OrderPayments $orders): int
    {
        $cutOff = now()->subMinutes(self::GRACE_MINUTES);

        $stale = Order::query()
            ->where('status', OrderStatus::Pending)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->whereNotNull('stripe_checkout_session_id')->where('expires_at', '<=', $cutOff))
                // Holding stock but never reached Stripe: the request stopped between the two.
                ->orWhere(fn (Builder $query) => $query->whereNull('stripe_checkout_session_id')->where('created_at', '<=', $cutOff)))
            ->orderBy('id')
            ->get();

        foreach ($stale as $order) {
            if ($order->stripe_checkout_session_id === null) {
                $orders->markExpired($order);

                continue;
            }

            try {
                $orders->apply($order, $payments->expireCheckoutSession($order->stripe_checkout_session_id));
            } catch (PaymentProviderUnavailable $exception) {
                $this->warn("Couldn’t check order {$order->reference()} with Stripe: {$exception->getMessage()}");
            }
        }

        $this->info("Swept {$stale->count()} orders.");

        return self::SUCCESS;
    }
}
