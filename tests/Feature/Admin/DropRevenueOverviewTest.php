<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Filament\Resources\Drops\DropResource;
use App\Filament\Resources\Drops\Widgets\DropRevenueOverview;
use App\Models\DropItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Database\Factories\OrderFactory;
use Tests\Fixtures\StockedDrop;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));
});

/**
 * An order in the drop for one of each item given, at the drop's prices.
 *
 * @param  list<DropItem>  $items
 * @param  array<string, mixed>  $attributes
 */
function orderInDrop(StockedDrop $shop, OrderFactory $order, array $items, array $attributes = []): Order
{
    $created = $order->for($shop->drop)->createOne(['total' => array_sum(array_map(fn (DropItem $item): int => $item->price, $items)), ...$attributes]);

    foreach ($items as $item) {
        OrderItem::factory()->for($created)->create(['drop_item_id' => $item->id, 'unit_price' => $item->price]);
    }

    return $created;
}

it('shows what a drop has taken after refunds, split into beef and delivery fees, with the average order', function (): void {
    $shop = StockedDrop::create();
    orderInDrop($shop, Order::factory(), [$shop->box, $shop->delivery]);
    orderInDrop($shop, Order::factory()->pickup(), [$shop->mince], ['status' => OrderStatus::Fulfilled, 'amount_refunded' => 600, 'refunded_at' => now()]);
    orderInDrop($shop, Order::factory()->pickup()->cashOwed(), [$shop->largeBox]);
    orderInDrop($shop, Order::factory()->pending(), [$shop->box, $shop->delivery], ['status' => OrderStatus::Expired]);
    orderInDrop($shop, Order::factory()->pending(), [$shop->box], ['status' => OrderStatus::Failed]);
    Order::factory()->create(['total' => 99900]);

    livewire(DropRevenueOverview::class, ['record' => $shop->drop])
        ->assertSeeInOrder([
            'Revenue', '$456', 'From 3 orders, after $6 refunded',
            'Beef', '$447', 'Plus $15 in delivery fees',
            'Average order', '$152', 'Including delivery',
        ]);
});

it('shows the cash still owed and the orders not paid yet', function (): void {
    $shop = StockedDrop::create();
    orderInDrop($shop, Order::factory()->pickup()->cashOwed(), [$shop->largeBox]);
    orderInDrop($shop, Order::factory()->pending(), [$shop->box, $shop->delivery]);
    orderInDrop($shop, Order::factory()->pending()->pickup(), [$shop->mince], ['status' => OrderStatus::Processing]);

    livewire(DropRevenueOverview::class, ['record' => $shop->drop])
        ->assertSeeInOrder([
            'Cash owed', '$275', '1 cash order still to pay',
            'At checkout', '$187', '2 orders not paid yet',
        ]);
});

it('shows nothing taken for a drop with no sales', function (): void {
    $shop = StockedDrop::create();
    orderInDrop($shop, Order::factory()->pending(), [$shop->box], ['status' => OrderStatus::Expired]);

    livewire(DropRevenueOverview::class, ['record' => $shop->drop])
        ->assertSeeInOrder(['Revenue', '$0', 'No orders yet', 'Average order', '—'])
        ->assertDontSee('Cash owed')
        ->assertDontSee('At checkout');
});

it('sits at the top of the drop’s page', function (): void {
    $shop = StockedDrop::create();

    $this->get(DropResource::getUrl('edit', ['record' => $shop->drop]))
        ->assertSeeInOrder(['Revenue', '$0', 'No orders yet', 'Average order']);
});
