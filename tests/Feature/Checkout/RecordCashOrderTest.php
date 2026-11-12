<?php

declare(strict_types=1);

use App\Actions\Orders\RecordCashOrder;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\CannotRecordOrder;
use App\Jobs\SendMetaEvent;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\StockedDrop;
use Tests\Fixtures\StockInvariant;

/**
 * A cash order for Sam: a pickup of one 5kg box, unless told otherwise.
 *
 * @param  array<int, int>|null  $quantities
 * @param  array{line1?: string|null, city?: string|null, postal_code?: string|null}|null  $address
 */
function recordCashOrder(
    StockedDrop $shop,
    ?array $quantities = null,
    DeliveryMethod $deliveryMethod = DeliveryMethod::Pickup,
    ?CarbonImmutable $deliveryDay = null,
    string $customerName = 'Sam Ferguson',
    ?string $phone = '0412 345 678',
    ?array $address = null,
    bool $paid = false,
): Order {
    return app(RecordCashOrder::class)(
        drop: $shop->drop,
        quantities: $quantities ?? [$shop->box->id => 1],
        deliveryMethod: $deliveryMethod,
        deliveryDay: $deliveryDay,
        customerName: $customerName,
        phone: $phone,
        address: $address,
        paid: $paid,
    );
}

/**
 * @return array{line1: string, city: string, postal_code: string}
 */
function sturtStreet(): array
{
    return ['line1' => '12 Sturt St', 'city' => 'Ballarat', 'postal_code' => '3350'];
}

it('puts a pickup aside as cash owed, holding its stock without emailing anyone or telling Meta', function (): void {
    Mail::fake();
    Queue::fake([SendMetaEvent::class]);
    enableMetaAds();
    $shop = StockedDrop::create(boxes: 5, mince: 10);

    $order = recordCashOrder($shop, quantities: [$shop->box->id => 1, $shop->mince->id => 2]);

    expect($order->refresh())
        ->status->toBe(OrderStatus::CashOwed)
        ->payment_method->toBe(PaymentMethod::Cash)
        ->total->toBe(16000 + 2 * 1200)
        ->customer_name->toBe('Sam Ferguson')
        ->phone->toBe('+61412345678')
        ->delivery_day->toBeNull()
        ->shipping_address->toBeNull()
        ->paid_at->toBeNull();
    expect($shop->box->refresh()->available)->toBe(4)
        ->and($shop->mince->refresh()->available)->toBe(8);
    StockInvariant::check($shop->drop);
    Mail::assertNothingOutgoing();
    Queue::assertNotPushed(SendMetaEvent::class);
});

it('records a delivery already paid for, adding the drop’s delivery fee and the address', function (): void {
    $this->freezeSecond();
    $shop = StockedDrop::create();
    $day = CarbonImmutable::parse($shop->drop->delivery_days[0]);

    $order = recordCashOrder($shop, deliveryMethod: DeliveryMethod::Delivery, deliveryDay: $day, address: sturtStreet(), paid: true);

    expect($order->refresh())
        ->status->toBe(OrderStatus::Paid)
        ->paid_at->toEqual(now()->toImmutable())
        ->total->toBe(16000 + 1500)
        ->shipping_address->toEqual(['line1' => '12 Sturt St', 'line2' => null, 'city' => 'Ballarat', 'state' => 'VIC', 'postal_code' => '3350', 'country' => 'AU']);
    expect($order->delivery_day?->toDateString())->toBe($day->toDateString());
    expect($order->items->pluck('quantity', 'drop_item_id')->all())->toBe([$shop->box->id => 1, $shop->delivery->id => 1]);
});

it('puts an order aside before the drop opens', function (): void {
    $shop = StockedDrop::create(drop: ['opens_at' => now()->addDay()]);

    $order = recordCashOrder($shop);

    expect($order->status)->toBe(OrderStatus::CashOwed)
        ->and($shop->box->refresh()->available)->toBe(4);
});

it('lets the owner put aside more than customers may order at once', function (): void {
    $shop = StockedDrop::create(boxes: 5);

    $order = recordCashOrder($shop, quantities: [$shop->box->id => 2, $shop->largeBox->id => 1]);

    expect($order->total)->toBe(2 * 16000 + 27500)
        ->and($shop->box->refresh()->available)->toBe(1);
});

it('refuses an order there isn’t enough stock left for, holding none of it', function (): void {
    $shop = StockedDrop::create(boxes: 1, mince: 0);

    expect(fn (): Order => recordCashOrder($shop, quantities: [$shop->box->id => 2, $shop->mince->id => 1]))
        ->toThrow(CannotRecordOrder::class, 'Only 1 × 5kg Beef Box left. 500g Beef Mince is sold out.');

    expect(Order::query()->count())->toBe(0)
        ->and($shop->box->refresh()->available)->toBe(1);
});

it('refuses a drop that has closed', function (): void {
    $shop = StockedDrop::create(drop: ['opens_at' => now()->subWeek(), 'closes_at' => now()->subDay()]);

    expect(fn (): Order => recordCashOrder($shop))->toThrow(CannotRecordOrder::class, 'has closed, so it can’t take new orders.');

    expect(Order::query()->count())->toBe(0);
});

it('refuses an order that is missing something or doesn’t fit the drop', function (Closure $attempt, string $message): void {
    $shop = StockedDrop::create();

    expect(fn () => $attempt($shop))->toThrow(CannotRecordOrder::class, $message);

    expect(Order::query()->count())->toBe(0);
})->with([
    'nothing chosen' => [fn (StockedDrop $shop): Order => recordCashOrder($shop, quantities: [$shop->box->id => 0]), 'Choose at least one item.'],
    'the delivery fee as an item' => [fn (StockedDrop $shop): Order => recordCashOrder($shop, quantities: [$shop->delivery->id => 1]), 'isn’t sold in'],
    'an item from another drop' => [fn (StockedDrop $shop): Order => recordCashOrder($shop, quantities: [DropItem::factory()->for(Drop::factory()->open())->create()->id => 1]), 'isn’t sold in'],
    'no name' => [fn (StockedDrop $shop): Order => recordCashOrder($shop, customerName: '  '), 'Add the name of the person the order is for.'],
    'not a mobile number' => [fn (StockedDrop $shop): Order => recordCashOrder($shop, phone: '5331 1234'), '5331 1234 isn’t an Australian mobile number.'],
    'a day the drop doesn’t deliver' => [
        fn (StockedDrop $shop): Order => recordCashOrder($shop, deliveryMethod: DeliveryMethod::Delivery, deliveryDay: now()->addMonth()->toImmutable(), address: sturtStreet()),
        'delivery days.',
    ],
    'a delivery with no address' => [
        fn (StockedDrop $shop): Order => recordCashOrder($shop, deliveryMethod: DeliveryMethod::Delivery, deliveryDay: CarbonImmutable::parse($shop->drop->delivery_days[0])),
        'Add the street, suburb and postcode to deliver to.',
    ],
    'a delivery with no mobile' => [
        fn (StockedDrop $shop): Order => recordCashOrder($shop, deliveryMethod: DeliveryMethod::Delivery, deliveryDay: CarbonImmutable::parse($shop->drop->delivery_days[0]), phone: null, address: sturtStreet()),
        'Add a mobile number for the delivery.',
    ],
]);
