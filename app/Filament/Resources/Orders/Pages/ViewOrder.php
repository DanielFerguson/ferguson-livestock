<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Resources\Pages\ViewRecord;
use LogicException;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    /**
     * Just the order's reference: everything else about it is below.
     */
    public function getTitle(): string
    {
        return $this->order()->reference();
    }

    /**
     * What's needed at a glance on the farm: where the order's at, whose it is, and how it goes out.
     */
    public function getSubheading(): string
    {
        $order = $this->order();

        return implode(' · ', array_filter([$order->status->getLabel(), $order->customer_name, $order->deliverySummary()], filled(...)));
    }

    protected function getHeaderActions(): array
    {
        return [
            OrderActions::markFulfilled(),
            OrderActions::giveFreeItem(),
            OrderActions::takeBackFreeItem(),
            OrderActions::markCashReceived(),
            OrderActions::cancelCashOrder(),
        ];
    }

    private function order(): Order
    {
        $record = $this->getRecord();

        return $record instanceof Order ? $record : throw new LogicException('The order page was opened without an order.');
    }
}
