<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Drop;
use App\Support\MetaAdResults;
use App\Support\Money;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * What Meta ads have brought in, by the shop's own records. Meta's own reports miss most of it: they only credit a
 * sale within 7 days of the ad click, and wait-list sign-ups usually buy at a later drop.
 */
class MetaAdsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Meta ads';

    protected ?string $description = 'From people who came through a link tagged utm_source=meta.';

    /**
     * Hidden until the pixel is set up or an ad has brought someone in.
     */
    public static function canView(): bool
    {
        return filled(config('services.meta.pixel_id')) || MetaAdResults::signUps()['sign_ups'] > 0;
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $signUps = MetaAdResults::signUps();

        $stats = [
            Stat::make('Wait-list sign-ups from ads', Number::format($signUps['sign_ups']))
                ->description(Number::format($signUps['sign_ups_last_30_days']).' in the last 30 days')
                ->icon(Heroicon::OutlinedUserPlus),
        ];

        $drop = Drop::featured();

        if (! $drop instanceof Drop) {
            return $stats;
        }

        $results = MetaAdResults::forDrop($drop);

        return [
            ...$stats,
            Stat::make('Ad sign-ups who bought', Number::format($results['of_whom_bought']).' of '.Number::format($results['sign_ups_before_drop']))
                ->description("In {$drop->name}, of those who joined before it opened")
                ->icon(Heroicon::OutlinedShoppingBag),
            Stat::make('Sales to ad customers', Money::format($results['revenue']))
                ->description(trans_choice(':count paid order|:count paid orders', $results['orders']).' in '.$drop->name)
                ->icon(Heroicon::OutlinedBanknotes),
        ];
    }
}
