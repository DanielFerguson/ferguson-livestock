<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Support\CustomerOrders;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /**
     * Each listed drop's orders by customer, worked out once a request rather than once a row. Livewire keeps only
     * public properties between requests, so this starts afresh each time.
     *
     * @var array<int, CustomerOrders>
     */
    private array $customerOrders = [];

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
            'to-pick-up' => $this->toHandOverTab('To pick up', DeliveryMethod::Pickup),
            'to-deliver' => $this->toHandOverTab('To deliver', DeliveryMethod::Delivery),
            'fulfilled' => $this->statusTab('Fulfilled', [OrderStatus::Fulfilled]),
            'cash-owed' => $this->countedStatusTab('Cash owed', [OrderStatus::CashOwed])->badgeColor('warning'),
            'not-paid' => $this->statusTab('Not paid', OrderStatus::notPaid()),
        ];
    }

    /**
     * How many orders the order's customer has on its drop, counting this one. An order that wasn't placed (still at
     * checkout, abandoned or failed) is on its own, so the Not paid tab doesn't group whole drops for nothing.
     */
    public function customerOrderCount(Order $order): int
    {
        if (! in_array($order->status, OrderStatus::placed(), true)) {
            return 1;
        }

        $this->customerOrders[$order->drop_id] ??= CustomerOrders::onDrop($order->drop_id);

        return $this->customerOrders[$order->drop_id]->of($order)->count();
    }

    /**
     * Sends an old link or bookmark to a tab that's gone, such as To fulfil, to the first tab, rather than listing
     * every order with no tab picked.
     */
    protected function loadDefaultActiveTab(): void
    {
        if ($this->activeTab !== null && ! array_key_exists($this->activeTab, $this->getCachedTabs())) {
            $this->activeTab = null;
        }

        parent::loadDefaultActiveTab();
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

    /**
     * Orders still to hand over by pickup or by delivery: paid ones, and cash ones put aside for friends, with a count
     * of them when there are any. The table's default sort puts To deliver in delivery-run order.
     */
    private function toHandOverTab(string $label, DeliveryMethod $method): Tab
    {
        $toHandOver = fn (Builder $query): Builder => $query->whereIn('status', OrderStatus::toFulfil())->where('delivery_method', $method);

        return Tab::make($label)
            ->modifyQueryUsing($toHandOver)
            ->badge(function () use ($toHandOver): ?int {
                $count = $toHandOver(Order::query())->count();

                return $count > 0 ? $count : null;
            });
    }
}
