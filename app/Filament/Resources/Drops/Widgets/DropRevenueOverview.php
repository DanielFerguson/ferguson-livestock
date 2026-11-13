<?php

declare(strict_types=1);

namespace App\Filament\Resources\Drops\Widgets;

use App\Models\Drop;
use App\Support\DropRevenue;
use App\Support\Money;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

/**
 * What a drop has taken, above its form. Refreshes every five seconds, so a live drop's figures keep up.
 */
class DropRevenueOverview extends StatsOverviewWidget
{
    /** Rendered with the page, so the figures are there the moment it opens. */
    protected static bool $isLazy = false;

    #[Locked]
    public ?Model $record = null;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        if (! $this->record instanceof Drop) {
            return [];
        }

        $sales = DropRevenue::forDrop($this->record);

        $stats = [
            Stat::make('Revenue', Money::format($sales['revenue']))
                ->description(self::ordersLine($sales['orders'], $sales['refunded']))
                ->icon(Heroicon::OutlinedBanknotes),
            Stat::make('Beef', Money::format($sales['beef']))
                ->description('Plus '.Money::format($sales['delivery_fees']).' in delivery fees')
                ->icon(Heroicon::OutlinedShoppingBag),
            Stat::make('Average order', $sales['orders'] > 0 ? Money::format((int) round($sales['revenue'] / $sales['orders'], -2)) : '—')
                ->description('Including delivery')
                ->icon(Heroicon::OutlinedReceiptPercent),
        ];

        if ($sales['cash_orders'] > 0) {
            $stats[] = Stat::make('Cash owed', Money::format($sales['cash_owed']))
                ->description(trans_choice(':count cash order still to pay|:count cash orders still to pay', $sales['cash_orders']))
                ->icon(Heroicon::OutlinedClock)
                ->color('warning');
        }

        if ($sales['orders_at_checkout'] > 0) {
            $stats[] = Stat::make('At checkout', Money::format($sales['at_checkout']))
                ->description(trans_choice(':count order not paid yet|:count orders not paid yet', $sales['orders_at_checkout']))
                ->icon(Heroicon::OutlinedCreditCard);
        }

        return $stats;
    }

    private static function ordersLine(int $orders, int $refunded): string
    {
        if ($orders === 0) {
            return 'No orders yet';
        }

        $line = trans_choice('From :count order|From :count orders', $orders);

        return $refunded > 0 ? $line.', after '.Money::format($refunded).' refunded' : $line;
    }
}
