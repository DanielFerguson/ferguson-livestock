<?php

declare(strict_types=1);

use App\Actions\Orders\GiveFreeItems;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\User;
use App\Stock\StockLedger;
use Tests\Fixtures\StockedDrop;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));

    $this->shop = StockedDrop::create(mince: 10);
    $this->order = app(StockLedger::class)->setAside($this->shop->drop, [$this->shop->box->id => 1], ['status' => OrderStatus::Paid, 'delivery_method' => DeliveryMethod::Pickup, 'customer_name' => 'Sam Buyer']);
});

it('adds a free item from the order’s page, taking it from the drop’s stock', function (): void {
    livewire(ViewOrder::class, ['record' => $this->order->id])
        ->callAction('giveFreeItem', ['drop_item_id' => $this->shop->mince->id, 'quantity' => 2])
        ->assertHasNoActionErrors()
        ->assertNotified('Free item added')
        ->assertSeeText('Free');

    expect($this->order->refresh()->total)->toBe(16000)
        ->and($this->shop->mince->refresh()->available)->toBe(8);
});

it('says why a free item can’t be added, keeping it open to change', function (): void {
    livewire(ViewOrder::class, ['record' => $this->order->id])
        ->callAction('giveFreeItem', ['drop_item_id' => $this->shop->mince->id, 'quantity' => 11])
        ->assertNotified('Not added')
        ->assertActionMounted('giveFreeItem');

    expect($this->shop->mince->refresh()->available)->toBe(10);
});

it('removes a free item from the order’s page, putting it back on sale', function (): void {
    app(GiveFreeItems::class)($this->order, [$this->shop->mince->id => 2]);

    livewire(ViewOrder::class, ['record' => $this->order->id])
        ->callAction('takeBackFreeItem')
        ->assertNotified('Free item removed');

    expect($this->order->items()->where('free', true)->count())->toBe(0)
        ->and($this->shop->mince->refresh()->available)->toBe(10);
});

it('only offers free items while the order’s stock is held, and removing them once there are some', function (): void {
    $atCheckout = Order::factory()->pending()->for($this->shop->drop)->create();

    livewire(ViewOrder::class, ['record' => $atCheckout->id])->assertActionHidden('giveFreeItem');
    livewire(ViewOrder::class, ['record' => $this->order->id])
        ->assertActionVisible('giveFreeItem')
        ->assertActionHidden('takeBackFreeItem');
});
