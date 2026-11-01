<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\Drop;
use App\Models\Order;
use App\Models\Subscriber;
use App\Support\MetaAdResults;

it('counts a drop’s paid orders from people Meta ads brought in, by mobile number or straight from an ad', function (): void {
    $drop = Drop::factory()->open()->create(['opens_at' => now()->subDay()]);
    $earlier = Drop::factory()->create(['opens_at' => now()->subMonths(3), 'closes_at' => now()->subMonths(3)->addWeek()]);

    $boughtFromAd = Subscriber::factory()->fromMetaAd()->create(['phone' => '+61400000001', 'created_at' => now()->subDays(45)]);
    Subscriber::factory()->fromMetaAd()->create(['phone' => '+61400000002', 'created_at' => now()->subDays(45)]);
    Subscriber::factory()->fromMetaAd()->create(['phone' => '+61400000003', 'created_at' => now()]);
    Subscriber::factory()->create(['phone' => '+61400000004', 'utm_source' => 'google', 'created_at' => now()->subDays(45)]);
    Subscriber::factory()->create(['phone' => '+61400000005', 'created_at' => now()->subDays(45)]);

    Order::factory()->for($drop)->create(['phone' => $boughtFromAd->phone, 'total' => 17500, 'amount_refunded' => 1500]);
    Order::factory()->for($drop)->create(['phone' => $boughtFromAd->phone, 'total' => 1200, 'status' => OrderStatus::Fulfilled]);
    Order::factory()->for($drop)->create(['phone' => '+61400000003', 'total' => 16000]);
    Order::factory()->for($drop)->create(['phone' => '+61400000099', 'total' => 27500, 'utm_source' => 'meta']);
    Order::factory()->for($drop)->create(['phone' => '+61400000004', 'total' => 16000]);
    Order::factory()->for($drop)->create(['phone' => '+61400000005', 'total' => 16000]);
    Order::factory()->for($drop)->pending()->create(['utm_source' => 'meta']);
    Order::factory()->for($drop)->create(['phone' => '+61400000002', 'status' => OrderStatus::Expired]);
    Order::factory()->for($earlier)->create(['phone' => '+61400000002']);

    expect(MetaAdResults::forDrop($drop))->toBe([
        'sign_ups_before_drop' => 2,
        'of_whom_bought' => 1,
        'orders' => 4,
        'revenue' => (17500 - 1500) + 1200 + 16000 + 27500,
    ])->and(MetaAdResults::signUps())->toBe(['sign_ups' => 3, 'sign_ups_last_30_days' => 1]);
});
