<?php

declare(strict_types=1);

use App\Actions\Orders\RecordCashOrder;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\CreateCashOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\PrintDeliveryRun;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Drop;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\DeliveryRun;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Arr;
use Tests\Fixtures\StockedDrop;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));
});

/**
 * A paid delivery order in the drop, for one item.
 *
 * @param  array<string, mixed>  $attributes
 */
function paidOrder(Drop $drop, string $suburb, string $postcode, array $attributes = []): Order
{
    $order = Order::factory()->for($drop)->create([
        'shipping_address' => ['line1' => '1 Main St', 'line2' => null, 'city' => $suburb, 'state' => 'VIC', 'postal_code' => $postcode, 'country' => 'AU'],
        ...$attributes,
    ]);
    OrderItem::factory()->for($order)->create(['quantity' => 2, 'unit_price' => 1200]);

    return $order;
}

it('lists paid orders, leaving out checkouts that were abandoned', function (): void {
    $drop = Drop::factory()->open()->create();
    $paid = paidOrder($drop, 'Ballarat', '3350');
    $abandoned = Order::factory()->for($drop)->pending()->create(['status' => OrderStatus::Expired]);

    livewire(ListOrders::class)
        ->assertCanSeeTableRecords([$paid])
        ->assertCanNotSeeTableRecords([$abandoned])
        ->assertSee($paid->reference());
});

it('shows the orders in each tab', function (string $tab, array $shown): void {
    $drop = Drop::factory()->open()->create();
    $orders = [
        'paid' => Order::factory()->for($drop)->create(),
        'clearing' => Order::factory()->for($drop)->create(['status' => OrderStatus::Processing]),
        'fulfilled' => Order::factory()->for($drop)->create(['status' => OrderStatus::Fulfilled, 'fulfilled_at' => now()]),
        'cash owed' => Order::factory()->for($drop)->cashOwed()->create(),
        'at checkout' => Order::factory()->for($drop)->pending()->create(),
        'abandoned' => Order::factory()->for($drop)->pending()->create(['status' => OrderStatus::Expired]),
        'failed' => Order::factory()->for($drop)->create(['status' => OrderStatus::Failed]),
    ];

    livewire(ListOrders::class)
        ->set('activeTab', $tab)
        ->assertCanSeeTableRecords(array_values(Arr::only($orders, $shown)))
        ->assertCanNotSeeTableRecords(array_values(Arr::except($orders, $shown)));
})->with([
    'all' => ['all', ['paid', 'clearing', 'fulfilled', 'cash owed']],
    'to fulfil' => ['to-fulfil', ['paid', 'cash owed']],
    'fulfilled' => ['fulfilled', ['fulfilled']],
    'cash owed' => ['cash-owed', ['cash owed']],
    'not paid' => ['not-paid', ['at checkout', 'abandoned', 'failed']],
]);

it('marks orders fulfilled, leaving any that aren’t paid', function (): void {
    $drop = Drop::factory()->open()->create();
    $paid = paidOrder($drop, 'Ballarat', '3350');
    $clearing = paidOrder($drop, 'Creswick', '3363', ['status' => OrderStatus::Processing]);

    livewire(ListOrders::class)
        ->selectTableRecords([$paid, $clearing])
        ->callAction(TestAction::make('markFulfilled')->table()->bulk());

    expect($paid->refresh()->status)->toBe(OrderStatus::Fulfilled)
        ->and($paid->fulfilled_at)->not->toBeNull()
        ->and($clearing->refresh()->status)->toBe(OrderStatus::Processing);
});

it('downloads a drop’s delivery run, sorted by suburb, with pickups last', function (): void {
    $drop = Drop::factory()->open()->create();
    $creswick = paidOrder($drop, 'Creswick', '3363', ['customer_name' => 'Cam']);
    $ballarat = paidOrder($drop, 'Ballarat', '3350', ['customer_name' => '=Ada']);
    $pickup = Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Pat']);
    OrderItem::factory()->for($pickup)->create();
    paidOrder(Drop::factory()->create(), 'Buninyong', '3357');

    $csv = DeliveryRun::forDrop($drop)->toCsv();

    expect(array_map(fn (string $line): string => str_getcsv($line, escape: '')[1] ?? '', array_slice(explode("\n", trim($csv)), 1)))
        ->toBe(["'=Ada", 'Cam', 'Pat'])
        ->and($csv)->toContain('2 × ');
});

it('quotes formula-like phone numbers and postcodes in the delivery run', function (): void {
    $drop = Drop::factory()->open()->create();
    paidOrder($drop, 'Ballarat', '=HYPERLINK("https://x.test")', ['phone' => '+1=2+3']);

    $row = str_getcsv(explode("\n", trim(DeliveryRun::forDrop($drop)->toCsv()))[1], escape: '');

    expect([$row[2], $row[8]])->toBe(["'+1=2+3", "'=HYPERLINK(\"https://x.test\")"]);
});

it('prints the delivery run by suburb, with pickups separate', function (): void {
    $drop = Drop::factory()->open()->create(['name' => 'October drop']);
    paidOrder($drop, 'Creswick', '3363', ['customer_name' => 'Cam']);
    paidOrder($drop, 'Ballarat', '3350', ['customer_name' => 'Ada']);
    $pickup = Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Pat']);
    OrderItem::factory()->for($pickup)->create();

    livewire(PrintDeliveryRun::class, ['drop' => $drop->id])
        ->assertSeeInOrder(['October drop', 'Deliveries', 'Ada', 'Ballarat', 'Cam', 'Creswick', 'Farm pickups', 'Pat']);
});

it('puts deliveries for either night after the named nights on the run', function (): void {
    $drop = Drop::factory()->open()->create(['delivery_days' => ['2026-11-14', '2026-11-15']]);
    paidOrder($drop, 'Ballarat', '3350', ['customer_name' => 'Ada', 'delivery_day' => null]);
    paidOrder($drop, 'Creswick', '3363', ['customer_name' => 'Cam', 'delivery_day' => '2026-11-15']);

    $days = array_map(fn (string $line): string => str_getcsv($line, escape: '')[5] ?? '', array_slice(explode("\n", trim(DeliveryRun::forDrop($drop)->toCsv())), 1));

    expect($days)->toBe(['Sun 15 Nov', 'Either night']);
    livewire(PrintDeliveryRun::class, ['drop' => $drop->id])->assertSeeInOrder(['Sunday 15 November', 'Cam', 'Either night', 'Ada']);
});

it('shows everything about an order', function (): void {
    $order = paidOrder(Drop::factory()->open()->create(), 'Ballarat', '3350', ['customer_name' => 'Sam Buyer', 'email' => 'buyer@example.test', 'delivery_method' => DeliveryMethod::Delivery]);

    OrderItem::factory()->for($order)->create(['quantity' => 1, 'unit_price' => 1500]);

    livewire(ViewOrder::class, ['record' => $order->id])
        ->assertSee(['Sam Buyer', 'buyer@example.test', '1 Main St', 'Ballarat', $order->reference(), '$24', '$15']);
});

it('keeps the delivery run behind the admin sign-in', function (): void {
    auth()->logout();

    $this->get(OrderResource::getUrl('delivery-run', ['drop' => Drop::factory()->create()]))->assertRedirect(route('filament.admin.auth.login'));
});

it('lists cash orders still to be paid for with the cash to collect on the delivery run', function (): void {
    $drop = Drop::factory()->open()->create(['name' => 'October drop']);
    paidOrder($drop, 'Ballarat', '3350', ['customer_name' => 'Ada', 'total' => 2400]);
    $owed = Order::factory()->for($drop)->cashOwed()->pickup()->create(['customer_name' => 'Pat', 'total' => 16000]);
    OrderItem::factory()->for($owed)->create();

    $rows = array_map(fn (string $line): array => str_getcsv($line, escape: ''), explode("\n", trim(DeliveryRun::forDrop($drop)->toCsv())));

    expect(array_map(fn (array $row): array => [$row[1], $row[11]], array_slice($rows, 1)))->toBe([['Ada', ''], ['Pat', '$160']]);
    livewire(PrintDeliveryRun::class, ['drop' => $drop->id])->assertSeeInOrder(['Farm pickups', 'Pat', 'Collect $160 cash']);
});

describe('cash orders', function (): void {
    beforeEach(function (): void {
        $this->undoRepeaterFake = Repeater::fake();
    });

    afterEach(fn () => ($this->undoRepeaterFake)());

    it('records a cash order from the dashboard, holding its stock', function (): void {
        $shop = StockedDrop::create(boxes: 5, mince: 10);

        livewire(CreateCashOrder::class)
            ->fillForm([
                'drop_id' => $shop->drop->id,
                'items' => [['drop_item_id' => $shop->box->id, 'quantity' => 1], ['drop_item_id' => $shop->mince->id, 'quantity' => 3]],
                'delivery_method' => DeliveryMethod::Pickup->value,
                'customer_name' => 'Sam Ferguson',
                'phone' => '0412 345 678',
                'paid' => false,
            ])
            ->assertSchemaStateSet(['total' => '$196'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(OrderResource::getUrl('view', ['record' => Order::query()->sole()]));

        expect(Order::query()->sole())
            ->status->toBe(OrderStatus::CashOwed)
            ->payment_method->toBe(PaymentMethod::Cash)
            ->customer_name->toBe('Sam Ferguson')
            ->total->toBe(16000 + 3 * 1200);
        expect($shop->box->refresh()->available)->toBe(4)
            ->and($shop->mince->refresh()->available)->toBe(7);
    });

    it('says why a cash order can’t be recorded, keeping the form', function (): void {
        $shop = StockedDrop::create(boxes: 1);

        livewire(CreateCashOrder::class)
            ->fillForm([
                'drop_id' => $shop->drop->id,
                'items' => [['drop_item_id' => $shop->box->id, 'quantity' => 2]],
                'delivery_method' => DeliveryMethod::Pickup->value,
                'customer_name' => 'Sam Ferguson',
            ])
            ->call('create')
            ->assertNotified('Order not recorded')
            ->assertNoRedirect();

        expect(Order::query()->count())->toBe(0);
    });

    it('asks for the delivery details only for a delivery', function (): void {
        $shop = StockedDrop::create();

        livewire(CreateCashOrder::class)
            ->fillForm([
                'drop_id' => $shop->drop->id,
                'items' => [['drop_item_id' => $shop->box->id, 'quantity' => 1]],
                'delivery_method' => DeliveryMethod::Delivery->value,
                'customer_name' => 'Sam Ferguson',
            ])
            ->call('create')
            ->assertHasFormErrors(['delivery_day' => 'required', 'phone' => 'required', 'street' => 'required', 'suburb' => 'required', 'postcode' => 'required']);

        expect(Order::query()->count())->toBe(0);
    });
});

it('marks a cash order paid when the cash comes in', function (): void {
    $owed = Order::factory()->for(Drop::factory()->open())->cashOwed()->create();

    livewire(ListOrders::class)->callAction(TestAction::make('markCashReceived')->table($owed));

    expect($owed->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($owed->paid_at)->not->toBeNull();
});

it('cancels a cash order from its page, putting its stock back on sale', function (): void {
    $shop = StockedDrop::create(boxes: 5);
    $owed = app(RecordCashOrder::class)(drop: $shop->drop, quantities: [$shop->box->id => 1], deliveryMethod: DeliveryMethod::Pickup, deliveryDay: null, customerName: 'Sam', phone: null, address: null, paid: false);

    livewire(ViewOrder::class, ['record' => $owed->id])->callAction('cancelCashOrder');

    expect($owed->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($shop->box->refresh()->available)->toBe(5);
});
