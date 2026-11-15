<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Support\Money;
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
     * What's needed at a glance on the farm: where the order's at, whose it is, and how it goes out. A cash order
     * still owed says what to collect, since its total is folded away below: "Cash owed, collect $110 · Mikele Linke
     * · Farm pickup".
     */
    public function getSubheading(): string
    {
        $order = $this->order();
        $status = $order->status === OrderStatus::CashOwed
            ? "{$order->status->getLabel()}, collect ".Money::format($order->total)
            : $order->status->getLabel();

        return implode(' · ', array_filter([$status, $order->customer_name, $order->deliverySummary()], filled(...)));
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
