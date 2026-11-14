<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Record cash order')->icon(Heroicon::OutlinedBanknotes),
            OrderActions::printDeliveryRun(),
            OrderActions::downloadDeliveryRun(),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => $this->statusTab('All', OrderStatus::placed()),
            'to-fulfil' => $this->countedStatusTab('To fulfil', OrderStatus::toFulfil()),
            'fulfilled' => $this->statusTab('Fulfilled', [OrderStatus::Fulfilled]),
            'cash-owed' => $this->countedStatusTab('Cash owed', [OrderStatus::CashOwed])->badgeColor('warning'),
            'not-paid' => $this->statusTab('Not paid', OrderStatus::notPaid()),
        ];
    }

    /**
     * A tab showing orders in the given statuses.
     *
     * @param  list<OrderStatus>  $statuses
     */
    private function statusTab(string $label, array $statuses): Tab
    {
        return Tab::make($label)->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', $statuses));
    }

    /**
     * A tab showing orders in the given statuses, with a count of them when there are any.
     *
     * @param  list<OrderStatus>  $statuses
     */
    private function countedStatusTab(string $label, array $statuses): Tab
    {
        return $this->statusTab($label, $statuses)->badge(function () use ($statuses): ?int {
            $count = Order::query()->whereIn('status', $statuses)->count();

            return $count > 0 ? $count : null;
        });
    }
}
