<?php

namespace App\Console\Commands;

use App\Checkout\SettleClosedCheckout;
use App\Exceptions\PaymentProviderUnavailable;
use Illuminate\Console\Command;

class SweepOrdersCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'orders:sweep';

    /**
     * @var string
     */
    protected $description = 'Settle orders whose payment page has closed, so a lost webhook never leaves stock held';

    public function handle(SettleClosedCheckout $settle): int
    {
        $stale = SettleClosedCheckout::due()->orderBy('id')->get();

        foreach ($stale as $order) {
            try {
                $settle($order);
            } catch (PaymentProviderUnavailable $exception) {
                $this->warn("Couldn’t check order {$order->reference()} with Stripe: {$exception->getMessage()}");
            }
        }

        $this->info("Swept {$stale->count()} orders.");

        return self::SUCCESS;
    }
}
