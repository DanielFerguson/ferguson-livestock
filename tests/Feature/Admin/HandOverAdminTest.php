<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Drop;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));
});

it('says under a customer’s name how many orders they have on the drop, and where a delivery’s going', function (): void {
    $drop = Drop::factory()->open()->create(['delivery_days' => ['2026-11-14']]);
    $delivery = Order::factory()->for($drop)->create([
        'customer_name' => 'Paul Clarke',
        'delivery_day' => '2026-11-14',
        'shipping_address' => ['line1' => '1 Main St', 'line2' => null, 'city' => 'Ballarat', 'state' => 'VIC', 'postal_code' => '3350', 'country' => 'AU'],
    ]);
    Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Paul Clarke']);
    $alone = Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Sam Buyer']);

    $page = livewire(ListOrders::class);

    $page->assertTableColumnHasDescription('customer_name', '2 orders · Sat 14 Nov · Ballarat', $delivery);
    $page->assertTableColumnHasDescription('customer_name', null, $alone);
});

it('fulfils a lone paid order in one tap', function (): void {
    $order = Order::factory()->pickup()->create(['customer_name' => 'Sam Buyer']);

    livewire(ViewOrder::class, ['record' => $order->id])
        ->mountAction('markFulfilled')
        ->assertNotified('Marked fulfilled');

    expect($order->refresh()->status)->toBe(OrderStatus::Fulfilled);
});

it('asks before handing over a customer’s orders, ticking each one still to hand over', function (): void {
    $drop = Drop::factory()->open()->create();
    $opened = Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Mikele Linke']);
    $forgotten = Order::factory()->for($drop)->pickup()->cashOwed()->create(['customer_name' => 'Mikele Linke', 'total' => 11000]);
    $collected = Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Mikele Linke', 'status' => OrderStatus::Fulfilled, 'fulfilled_at' => '2026-11-14 01:00:00']);

    livewire(ViewOrder::class, ['record' => $opened->id])
        ->mountAction('markFulfilled')
        ->assertActionMounted('markFulfilled')
        ->assertActionDataSet(['orders' => [$opened->id, $forgotten->id]])
        ->assertMountedActionModalSee([$forgotten->reference().' · collect $110 cash', $collected->reference().' · already handed over Sat 14 Nov']);

    expect($opened->refresh()->status)->toBe(OrderStatus::Paid);
});

it('hands over the ticked orders, taking the cash for a cash order, and leaves the rest', function (): void {
    $drop = Drop::factory()->open()->create();
    $opened = Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Tanya Bransgrove', 'phone' => '+61488176630']);
    $cash = Order::factory()->for($drop)->pickup()->cashOwed()->create(['customer_name' => 'Tanya', 'phone' => '+61488176630']);
    $notToday = Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Tanya Bransgrove']);

    livewire(ViewOrder::class, ['record' => $opened->id])
        ->callAction('markFulfilled', ['orders' => [$opened->id, $cash->id]])
        ->assertHasNoActionErrors()
        ->assertNotified('2 orders marked fulfilled');

    expect($opened->refresh()->status)->toBe(OrderStatus::Fulfilled)
        ->and($cash->refresh()->status)->toBe(OrderStatus::Fulfilled)
        ->and($cash->paid_at)->not->toBeNull()
        ->and($notToday->refresh()->status)->toBe(OrderStatus::Paid);
});

it('says which order had changed when it was handed over from another phone meanwhile', function (): void {
    $drop = Drop::factory()->open()->create();
    $opened = Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Mikele Linke']);
    $elsewhere = Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Mikele Linke']);
    $page = livewire(ViewOrder::class, ['record' => $opened->id])->mountAction('markFulfilled');
    Order::query()->whereKey($elsewhere->id)->update(['status' => OrderStatus::Fulfilled, 'fulfilled_at' => now()]);

    $page->callMountedAction()->assertNotified(Notification::make()->title('1 of 2 marked fulfilled')->body("{$elsewhere->reference()} had changed.")->warning());

    expect($opened->refresh()->status)->toBe(OrderStatus::Fulfilled);
});

it('won’t hand over with nothing ticked', function (): void {
    $drop = Drop::factory()->open()->create();
    $opened = Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Mikele Linke']);
    Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Mikele Linke']);

    livewire(ViewOrder::class, ['record' => $opened->id])
        ->callAction('markFulfilled', ['orders' => []])
        ->assertHasActionErrors(['orders' => 'required']);

    expect($opened->refresh()->status)->toBe(OrderStatus::Paid);
});

it('won’t hand over someone else’s order', function (): void {
    $drop = Drop::factory()->open()->create();
    $opened = Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Mikele Linke']);
    Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Mikele Linke']);
    $stranger = Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Sam Buyer']);

    livewire(ViewOrder::class, ['record' => $opened->id])
        ->callAction('markFulfilled', ['orders' => [$opened->id, $stranger->id]])
        ->assertHasActionErrors();

    expect($opened->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($stranger->refresh()->status)->toBe(OrderStatus::Paid);
});

it('offers handing over on an order already collected while another of the customer’s is waiting', function (): void {
    $drop = Drop::factory()->open()->create();
    $collected = Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Mikele Linke', 'status' => OrderStatus::Fulfilled, 'fulfilled_at' => now()]);
    Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Mikele Linke']);

    livewire(ViewOrder::class, ['record' => $collected->id])->assertActionVisible('markFulfilled');
});

it('asks before handing over the customer’s waiting order from one already collected', function (): void {
    $drop = Drop::factory()->open()->create();
    $collected = Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Mikele Linke', 'status' => OrderStatus::Fulfilled, 'fulfilled_at' => now()]);
    $waiting = Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Mikele Linke']);

    livewire(ViewOrder::class, ['record' => $collected->id])
        ->mountAction('markFulfilled')
        ->assertActionMounted('markFulfilled')
        ->assertActionDataSet(['orders' => [$waiting->id]]);

    expect($waiting->refresh()->status)->toBe(OrderStatus::Paid);
});

it('lists a customer’s order whose payment is still clearing without ticking it, and leaves it clearing', function (): void {
    $drop = Drop::factory()->open()->create();
    $clearing = Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Mikele Linke', 'status' => OrderStatus::Processing, 'paid_at' => null]);
    $waiting = Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Mikele Linke']);

    $page = livewire(ViewOrder::class, ['record' => $clearing->id])
        ->mountAction('markFulfilled')
        ->assertActionDataSet(['orders' => [$waiting->id]]);
    $page->assertMountedActionModalSee($clearing->reference().' · payment still clearing');
    $page->assertSchemaComponentExists('orders', checkComponentUsing: fn (CheckboxList $orders): bool => array_keys($orders->getEnabledOptions()) === [$waiting->id])
        ->callMountedAction();

    expect($clearing->refresh()->status)->toBe(OrderStatus::Processing)
        ->and($waiting->refresh()->status)->toBe(OrderStatus::Fulfilled);
});

it('stops offering it once everything the customer ordered is handed over', function (): void {
    $collected = Order::factory()->pickup()->create(['status' => OrderStatus::Fulfilled, 'fulfilled_at' => now()]);

    livewire(ViewOrder::class, ['record' => $collected->id])->assertActionHidden('markFulfilled');
});

it('offers Mark fulfilled on a list row only while that order is still to hand over', function (): void {
    $drop = Drop::factory()->open()->create();
    $paid = Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Mikele Linke']);
    $owed = Order::factory()->for($drop)->pickup()->cashOwed()->create(['customer_name' => 'Mikele Linke']);
    $collected = Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Mikele Linke', 'status' => OrderStatus::Fulfilled, 'fulfilled_at' => now()]);

    livewire(ListOrders::class)
        ->assertActionVisible(TestAction::make('markFulfilled')->table($paid))
        ->assertActionVisible(TestAction::make('markFulfilled')->table($owed))
        ->assertActionHidden(TestAction::make('markFulfilled')->table($collected));
});

it('lists everything the customer has on the drop on each of their orders, this one first', function (): void {
    $drop = Drop::factory()->open()->create(['delivery_days' => ['2026-11-14']]);
    $box = Order::factory()->for($drop)->create(['customer_name' => 'Mikele Linke', 'delivery_day' => '2026-11-14']);
    OrderItem::factory()->for($box)->for(Product::factory()->create(['name' => '10kg Beef Box']))->create();
    $mince = Order::factory()->for($drop)->pickup()->cashOwed()->create(['customer_name' => 'Mikele Linke', 'total' => 11000]);
    OrderItem::factory()->for($mince)->for(Product::factory()->create(['name' => '5kg Beef Mince Pack']))->create();

    livewire(ViewOrder::class, ['record' => $mince->id])
        ->assertSeeText(['Cash owed · Mikele Linke · Farm pickup'])
        ->assertSeeTextInOrder([
            'Everything for Mikele · 2 orders',
            $mince->reference(), 'This one', 'Collect $110 cash', '5kg Beef Mince Pack',
            $box->reference(), 'Delivery, Sat 14 Nov', '10kg Beef Box',
        ]);
});
