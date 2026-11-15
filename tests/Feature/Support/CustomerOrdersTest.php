<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\Drop;
use App\Models\Order;
use App\Support\CustomerOrders;

it('puts orders together when they share a phone, email or name', function (array $first, array $second): void {
    $drop = Drop::factory()->open()->create();
    $orders = Order::factory()->for($drop)->count(2)->sequence($first, $second)->create();

    expect(CustomerOrders::onDrop($drop->id)->of($orders->firstOrFail())->modelKeys())->toBe($orders->modelKeys());
})->with([
    'the same mobile, however it was written' => [
        ['phone' => '+61412345678', 'email' => 'sam@example.test', 'customer_name' => 'Sam Buyer'],
        ['phone' => '0412 345 678', 'email' => 'alex@example.test', 'customer_name' => 'Alex Buyer'],
    ],
    'the same email in another case' => [
        ['phone' => '+61412345678', 'email' => 'sam@example.test', 'customer_name' => 'Sam Buyer'],
        ['phone' => '+61487654321', 'email' => 'Sam@Example.test', 'customer_name' => 'Alex Buyer'],
    ],
    'the same name, ignoring case and spaces' => [
        ['phone' => '+61412345678', 'email' => 'sam@example.test', 'customer_name' => 'Sam Buyer'],
        ['phone' => null, 'email' => null, 'customer_name' => ' sam  BUYER '],
    ],
    'the same name with a curly apostrophe' => [
        ['phone' => '+61412345678', 'email' => 'kate@example.test', 'customer_name' => 'Kate O’Brien'],
        ['phone' => '+61487654321', 'email' => 'katie@example.test', 'customer_name' => "kate o'brien"],
    ],
]);

it('keeps apart customers who share nothing', function (): void {
    $drop = Drop::factory()->open()->create();
    $sam = Order::factory()->for($drop)->create(['phone' => '+61412345678', 'email' => 'sam@example.test', 'customer_name' => 'Sam Buyer']);
    Order::factory()->for($drop)->create(['phone' => '+61487654321', 'email' => 'alex@example.test', 'customer_name' => 'Alex Buyer']);

    expect(CustomerOrders::onDrop($drop->id)->of($sam)->modelKeys())->toBe([$sam->id]);
});

it('never puts orders together over details that are blank', function (): void {
    $drop = Drop::factory()->open()->create();
    $first = Order::factory()->for($drop)->cashOwed()->create(['phone' => null, 'customer_name' => null]);
    Order::factory()->for($drop)->cashOwed()->create(['phone' => '', 'email' => '', 'customer_name' => '  ']);

    expect(CustomerOrders::onDrop($drop->id)->of($first)->modelKeys())->toBe([$first->id]);
});

it('puts together orders linked only through another of the customer’s orders', function (): void {
    $drop = Drop::factory()->open()->create();
    $byName = Order::factory()->for($drop)->cashOwed()->create(['phone' => null, 'customer_name' => 'Aaron Baker']);
    $byPhone = Order::factory()->for($drop)->create(['phone' => '+61437984526', 'email' => 'a.baker@example.test', 'customer_name' => 'A Baker']);
    $both = Order::factory()->for($drop)->create(['phone' => '+61437984526', 'email' => 'aaron@example.test', 'customer_name' => 'Aaron Baker']);

    expect(CustomerOrders::onDrop($drop->id)->of($byName)->modelKeys())->toBe([$byName->id, $byPhone->id, $both->id]);
});

it('matches an order by any detail on the customer’s earlier orders, not just the latest', function (): void {
    $drop = Drop::factory()->open()->create();
    $first = Order::factory()->for($drop)->create(['phone' => '+61437984526', 'email' => 'aaron@example.test', 'customer_name' => 'Aaron Baker']);
    $nameOnly = Order::factory()->for($drop)->cashOwed()->create(['phone' => null, 'customer_name' => 'Aaron Baker']);
    $phoneOnly = Order::factory()->for($drop)->create(['phone' => '+61437984526', 'email' => null, 'customer_name' => 'A Baker']);

    expect(CustomerOrders::onDrop($drop->id)->of($first)->modelKeys())->toBe([$first->id, $nameOnly->id, $phoneOnly->id]);
});

it('starts with the order asked about, then the rest oldest first', function (): void {
    $drop = Drop::factory()->open()->create();
    $first = Order::factory()->for($drop)->create(['customer_name' => 'Sam Buyer']);
    $second = Order::factory()->for($drop)->create(['customer_name' => 'Sam Buyer']);
    $third = Order::factory()->for($drop)->create(['customer_name' => 'Sam Buyer']);

    expect(CustomerOrders::onDrop($drop->id)->of($second)->modelKeys())->toBe([$second->id, $first->id, $third->id]);
});

it('leaves out the customer’s orders on other drops', function (): void {
    $drop = Drop::factory()->open()->create();
    $order = Order::factory()->for($drop)->create(['customer_name' => 'Sam Buyer']);
    Order::factory()->for(Drop::factory()->open())->create(['customer_name' => 'Sam Buyer']);

    expect(CustomerOrders::onDrop($drop->id)->of($order)->modelKeys())->toBe([$order->id]);
});

it('puts in the customer’s orders that are clearing, handed over or owed in cash', function (OrderStatus $status): void {
    $drop = Drop::factory()->open()->create();
    $order = Order::factory()->for($drop)->create(['customer_name' => 'Sam Buyer']);
    $other = Order::factory()->for($drop)->create(['customer_name' => 'Sam Buyer', 'status' => $status]);

    expect(CustomerOrders::onDrop($drop->id)->of($order)->modelKeys())->toBe([$order->id, $other->id]);
})->with([
    'clearing' => OrderStatus::Processing,
    'handed over' => OrderStatus::Fulfilled,
    'owed in cash' => OrderStatus::CashOwed,
]);

it('leaves out checkouts that didn’t become orders', function (OrderStatus $status): void {
    $drop = Drop::factory()->open()->create();
    $order = Order::factory()->for($drop)->create(['customer_name' => 'Sam Buyer']);
    Order::factory()->for($drop)->create(['customer_name' => 'Sam Buyer', 'status' => $status]);

    expect(CustomerOrders::onDrop($drop->id)->of($order)->modelKeys())->toBe([$order->id]);
})->with([
    'still at checkout' => OrderStatus::Pending,
    'abandoned' => OrderStatus::Expired,
    'payment failed' => OrderStatus::Failed,
]);

it('gives a checkout that didn’t become an order on its own', function (): void {
    $drop = Drop::factory()->open()->create();
    Order::factory()->for($drop)->create(['customer_name' => 'Sam Buyer']);
    $abandoned = Order::factory()->for($drop)->create(['customer_name' => 'Sam Buyer', 'status' => OrderStatus::Expired]);

    expect(CustomerOrders::onDrop($drop->id)->of($abandoned)->modelKeys())->toBe([$abandoned->id]);
});
