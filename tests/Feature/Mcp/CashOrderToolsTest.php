<?php

declare(strict_types=1);

use App\Actions\Orders\RecordCashOrder;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Mcp\Servers\FergusonLivestockServer;
use App\Mcp\Tools\Orders\CancelCashOrderTool;
use App\Mcp\Tools\Orders\MarkCashReceivedTool;
use App\Mcp\Tools\Orders\RecordCashOrderTool;
use App\Models\Order;
use App\Models\User;
use Tests\Fixtures\StockedDrop;

beforeEach(function (): void {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->server = FergusonLivestockServer::actingAs(User::factory()->create(['email' => 'daniel@example.com']));
});

it('records a cash delivery for a friend by product, adding the delivery fee', function (): void {
    $shop = StockedDrop::create(boxes: 5);

    $this->server->tool(RecordCashOrderTool::class, [
        'drop_id' => $shop->drop->id,
        'items' => [['product_id' => $shop->box->product_id, 'quantity' => 1]],
        'delivery_method' => 'delivery',
        'delivery_day' => $shop->drop->delivery_days[0],
        'customer_name' => 'Sam Ferguson',
        'phone' => '0412 345 678',
        'street' => '12 Sturt St',
        'suburb' => 'Ballarat',
        'postcode' => '3350',
    ])->assertOk()->assertSee('"status":"cash_owed"')->assertSee('"payment_method":"cash"');

    expect(Order::query()->sole())
        ->payment_method->toBe(PaymentMethod::Cash)
        ->total->toBe(16000 + 1500);
    expect($shop->box->refresh()->available)->toBe(4);
});

it('says why a cash order can’t be recorded', function (): void {
    $shop = StockedDrop::create(boxes: 1);

    $this->server->tool(RecordCashOrderTool::class, [
        'drop_id' => $shop->drop->id,
        'items' => [['product_id' => $shop->box->product_id, 'quantity' => 2]],
        'delivery_method' => 'pickup',
        'customer_name' => 'Sam Ferguson',
    ])->assertHasErrors(['Only 1 × 5kg Beef Box left.']);

    expect(Order::query()->count())->toBe(0);
});

it('marks one cash order paid and cancels another by reference, only while the cash is owed', function (): void {
    $shop = StockedDrop::create(boxes: 5);
    $record = fn (): Order => app(RecordCashOrder::class)(drop: $shop->drop, quantities: [$shop->box->id => 1], deliveryMethod: DeliveryMethod::Pickup, deliveryDay: null, customerName: 'Sam', phone: null, address: null, paid: false);
    $paying = $record();
    $cancelling = $record();

    $this->server->tool(MarkCashReceivedTool::class, ['order' => $paying->reference()])->assertOk();
    $this->server->tool(CancelCashOrderTool::class, ['order' => $cancelling->reference()])->assertOk();
    $this->server->tool(CancelCashOrderTool::class, ['order' => $paying->reference()])->assertHasErrors(['only a Cash owed order can be cancelled']);

    expect($paying->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($cancelling->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($shop->box->refresh()->available)->toBe(4);
});
