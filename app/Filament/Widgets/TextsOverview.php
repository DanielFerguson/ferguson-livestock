<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SmsReplies\SmsReplyResource;
use App\Models\Subscriber;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * The wait list at a glance on the dashboard.
 */
class TextsOverview extends StatsOverviewWidget
{
    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $unread = SmsReplyResource::unreadCount();

        return [
            Stat::make('Subscribed', Number::format(Subscriber::query()->subscribed()->count()))
                ->description('On the wait list for texts'),
            Stat::make('Unread replies', Number::format($unread))
                ->url(SmsReplyResource::getUrl())
                ->color($unread > 0 ? 'warning' : 'gray'),
        ];
    }
}
