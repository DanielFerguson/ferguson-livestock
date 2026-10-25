<?php

namespace App\Jobs;

use App\Checkout\SettleClosedCheckout;
use App\Enums\OrderStatus;
use App\Jobs\Concerns\WaitsUntilDue;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Settles one checkout once its payment page has closed, in case Stripe's webhook never arrives. Queued as the
 * checkout starts; the hourly orders:sweep catches any that go missing.
 */
class SettleOrderWhenPaymentPageCloses implements ShouldQueue
{
    use Queueable, WaitsUntilDue;

    /**
     * Stripe being unreachable is retried. Until then the order stays pending, so it's safe to settle later.
     */
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 120, 300, 600];

    public function __construct(public int $orderId) {}

    public function handle(SettleClosedCheckout $settle): void
    {
        $order = Order::query()->whereKey($this->orderId)->where('status', OrderStatus::Pending)->first();

        if ($order === null || $this->waitingUntil(SettleClosedCheckout::dueAt($order))) {
            return;
        }

        $settle($order);
    }
}
