<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Drop;
use App\Support\DeliveryRun;
use Filament\Resources\Pages\Page;
use LogicException;

/**
 * A drop's delivery run laid out for printing. It sits inside the admin, so the admin sign-in and its
 * authenticator check apply.
 */
class PrintDeliveryRun extends Page
{
    protected static string $resource = OrderResource::class;

    protected string $view = 'filament.orders.print-delivery-run';

    public ?Drop $deliveryDrop = null;

    public function mount(Drop $drop): void
    {
        $this->deliveryDrop = $drop;
    }

    public function getTitle(): string
    {
        return "Delivery run: {$this->drop()->name}";
    }

    public function run(): DeliveryRun
    {
        return DeliveryRun::forDrop($this->drop());
    }

    private function drop(): Drop
    {
        return $this->deliveryDrop ?? throw new LogicException('The delivery run page was opened without a drop.');
    }
}
