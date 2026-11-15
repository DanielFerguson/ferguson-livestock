<?php

declare(strict_types=1);

use App\Actions\Orders\HandOverOrders;
use App\Enums\OrderStatus;
use App\Models\Order;

it('marks a paid order fulfilled', function (): void {
    $order = Order::factory()->create();

    $notHandedOver = app(HandOverOrders::class)([$order]);

    expect($notHandedOver)->toBe([])
        ->and($order->refresh())->status->toBe(OrderStatus::Fulfilled)->fulfilled_at->not->toBeNull();
});

it('records the cash for a cash order, then marks it fulfilled', function (): void {
    $order = Order::factory()->cashOwed()->create();

    app(HandOverOrders::class)([$order]);

    expect($order->refresh())
        ->status->toBe(OrderStatus::Fulfilled)
        ->paid_at->not->toBeNull()
        ->fulfilled_at->not->toBeNull();
});

it('leaves an order handed over from another phone as it was, and says so', function (): void {
    $order = Order::factory()->create();
    Order::query()->whereKey($order->id)->update(['status' => OrderStatus::Fulfilled, 'fulfilled_at' => '2026-11-14 01:00:00']);

    $notHandedOver = app(HandOverOrders::class)([$order]);

    expect(array_map(fn (Order $left): int => $left->id, $notHandedOver))->toBe([$order->id])
        ->and($order->refresh()->fulfilled_at?->toDateTimeString())->toBe('2026-11-14 01:00:00');
});

it('leaves a cash order cancelled in the meantime unpaid, and says so', function (): void {
    $order = Order::factory()->cashOwed()->create();
    Order::query()->whereKey($order->id)->update(['status' => OrderStatus::Expired]);

    $notHandedOver = app(HandOverOrders::class)([$order]);

    expect(array_map(fn (Order $left): int => $left->id, $notHandedOver))->toBe([$order->id])
        ->and($order->refresh())->status->toBe(OrderStatus::Expired)->paid_at->toBeNull();
});
