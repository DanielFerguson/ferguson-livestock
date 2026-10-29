<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Checkout\CheckoutRequest;
use App\Checkout\StartCheckout;
use App\Enums\DeliveryMethod;
use App\Exceptions\CheckoutBusy;
use App\Exceptions\InsufficientStock;
use App\Models\Drop;
use App\Stock\StockLedger;
use Closure;
use Tests\Fakes\FakePaymentGateway;

/**
 * A customer trying to buy a whole cart, run in its own process by the concurrency tests. Like ConcurrentBuyer, it
 * lives here because child processes can't load Pest's generated test classes.
 */
final class ConcurrentCart
{
    /**
     * Straight at the stock ledger, as the lowest layer of checkout.
     *
     * @param  array<int, int>  $quantities  drop item ID => quantity, in the order the buyer chose them
     * @return Closure(): string 'held', 'sold out' or 'busy'
     */
    public static function holds(int $dropId, array $quantities, float $startAt, string $buyer): Closure
    {
        return static function () use ($dropId, $quantities, $startAt, $buyer): string {
            time_sleep_until($startAt);

            try {
                app(StockLedger::class)->reserve(Drop::query()->findOrFail($dropId), $quantities, DeliveryMethod::Pickup, null, $buyer, now()->addMinutes(15));

                return 'held';
            } catch (InsufficientStock) {
                return 'sold out';
            } catch (CheckoutBusy) {
                return 'busy';
            }
        };
    }

    /**
     * Through the whole of checkout (rate limits, the hold, Stripe's payment page), against a fake Stripe.
     *
     * @param  array<int, int>  $quantities
     * @return Closure(): string 'held', 'sold out' or 'busy'
     */
    public static function checksOut(int $dropId, array $quantities, float $startAt, string $buyer): Closure
    {
        return static function () use ($dropId, $quantities, $startAt, $buyer): string {
            FakePaymentGateway::swap()->withSessionIdPrefix("cs_test_{$buyer}_");
            time_sleep_until($startAt);

            try {
                app(StartCheckout::class)(new CheckoutRequest($dropId, $quantities, DeliveryMethod::Pickup, null, $buyer, '192.0.2.'.(crc32($buyer) % 250 + 1)));

                return 'held';
            } catch (InsufficientStock) {
                return 'sold out';
            } catch (CheckoutBusy) {
                return 'busy';
            }
        };
    }
}
