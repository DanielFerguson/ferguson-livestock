<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Conversations;
use App\Models\Subscriber;
use App\Sms\ConversationList;
use Filament\Support\Icons\Heroicon;
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
        $unread = ConversationList::attentionCount();

        return [
            Stat::make('Subscribed', Number::format(Subscriber::query()->subscribed()->count()))
                ->description('On the wait list for texts')
                ->icon(Heroicon::OutlinedUsers),
            Stat::make('Need attention', Number::format($unread))
                ->description('Unread, or flagged to come back to')
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->url(Conversations::getUrl())
                ->color($unread > 0 ? 'warning' : 'gray'),
        ];
    }
}
