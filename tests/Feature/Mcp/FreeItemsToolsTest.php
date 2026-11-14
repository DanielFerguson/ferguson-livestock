<?php

declare(strict_types=1);

use App\Actions\Orders\GiveFreeItems;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Mcp\Servers\FergusonLivestockServer;
use App\Mcp\Tools\Orders\GiveFreeItemsTool;
use App\Mcp\Tools\Orders\RemoveFreeItemTool;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Stock\StockLedger;
use Tests\Fixtures\StockedDrop;

beforeEach(function (): void {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->server = FergusonLivestockServer::actingAs(User::factory()->create(['email' => 'daniel@example.com']));
});

/**
 * Sam's paid pickup of one 5kg box, its stock held as checkout holds it.
 */
function paidPickup(StockedDrop $shop): Order
{
    return app(StockLedger::class)->setAside($shop->drop, [$shop->box->id => 1], ['status' => OrderStatus::Paid, 'delivery_method' => DeliveryMethod::Pickup, 'customer_name' => 'Sam Buyer']);
}

it('gives a customer a sample pack on their order, by reference and product', function (): void {
    $shop = StockedDrop::create(mince: 10);
    $order = paidPickup($shop);

    $this->server->tool(GiveFreeItemsTool::class, [
        'order' => $order->reference(),
        'items' => [['product_id' => $shop->mince->product_id, 'quantity' => 2]],
    ])->assertOk()->assertSee('"given":true');

    expect($order->refresh()->total)->toBe(16000)
        ->and($order->items()->where('free', true)->sum('quantity'))->toBe(2)
        ->and($shop->mince->refresh()->available)->toBe(8);
});

it('says why a free item can’t be given', function (Closure $productId, string $message): void {
    $shop = StockedDrop::create(mince: 1);

    $this->server->tool(GiveFreeItemsTool::class, [
        'order' => paidPickup($shop)->reference(),
        'items' => [['product_id' => $productId($shop), 'quantity' => 2]],
    ])->assertHasErrors([$message]);

    expect($shop->mince->refresh()->available)->toBe(1);
})->with([
    'not enough left' => [fn (StockedDrop $shop): int => $shop->mince->product_id, 'Only 1 × 500g Beef Mince left.'],
    'not on the drop' => [fn (StockedDrop $shop): int => Product::factory()->create()->id, 'isn’t on'],
]);

it('takes a free item back off, leaving what was paid for', function (): void {
    $shop = StockedDrop::create(mince: 10);
    $order = paidPickup($shop);
    app(GiveFreeItems::class)($order, [$shop->mince->id => 2]);

    $this->server->tool(RemoveFreeItemTool::class, ['order' => $order->reference(), 'product_id' => $shop->box->product_id])
        ->assertHasErrors(['has no free item']);
    $this->server->tool(RemoveFreeItemTool::class, ['order' => $order->reference(), 'product_id' => $shop->mince->product_id])
        ->assertOk()->assertSee('"removed":true');

    expect($order->items()->pluck('drop_item_id')->all())->toBe([$shop->box->id])
        ->and($shop->mince->refresh()->available)->toBe(10);
});
