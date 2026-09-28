<?php

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\PrintDeliveryRun;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Drop;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\DeliveryRun;
use Filament\Actions\Testing\TestAction;

use function Pest\Livewire\livewire;

beforeEach(function () {
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

it('lists paid orders, leaving out checkouts that were abandoned', function () {
    $drop = Drop::factory()->open()->create();
    $paid = paidOrder($drop, 'Ballarat', '3350');
    $abandoned = Order::factory()->for($drop)->pending()->create(['status' => OrderStatus::Expired]);

    livewire(ListOrders::class)
        ->assertCanSeeTableRecords([$paid])
        ->assertCanNotSeeTableRecords([$abandoned])
        ->assertSee($paid->reference());
});

it('marks orders fulfilled, leaving any that aren’t paid', function () {
    $drop = Drop::factory()->open()->create();
    $paid = paidOrder($drop, 'Ballarat', '3350');
    $clearing = paidOrder($drop, 'Creswick', '3363', ['status' => OrderStatus::Processing]);

    livewire(ListOrders::class)
        ->filterTable('status', [OrderStatus::Paid->value, OrderStatus::Processing->value])
        ->selectTableRecords([$paid, $clearing])
        ->callAction(TestAction::make('markFulfilled')->table()->bulk());

    expect($paid->refresh()->status)->toBe(OrderStatus::Fulfilled)
        ->and($paid->fulfilled_at)->not->toBeNull()
        ->and($clearing->refresh()->status)->toBe(OrderStatus::Processing);
});

it('downloads a drop’s delivery run, sorted by suburb, with pickups last', function () {
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

it('prints the delivery run by suburb, with pickups separate', function () {
    $drop = Drop::factory()->open()->create(['name' => 'October drop']);
    paidOrder($drop, 'Creswick', '3363', ['customer_name' => 'Cam']);
    paidOrder($drop, 'Ballarat', '3350', ['customer_name' => 'Ada']);
    $pickup = Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Pat']);
    OrderItem::factory()->for($pickup)->create();

    livewire(PrintDeliveryRun::class, ['drop' => $drop->id])
        ->assertSeeInOrder(['October drop', 'Deliveries', 'Ada', 'Ballarat', 'Cam', 'Creswick', 'Farm pickups', 'Pat']);
});

it('shows everything about an order', function () {
    $order = paidOrder(Drop::factory()->open()->create(), 'Ballarat', '3350', ['customer_name' => 'Sam Buyer', 'email' => 'buyer@example.test', 'delivery_method' => DeliveryMethod::Delivery]);

    OrderItem::factory()->for($order)->create(['quantity' => 1, 'unit_price' => 1500]);

    livewire(ViewOrder::class, ['record' => $order->id])
        ->assertSee(['Sam Buyer', 'buyer@example.test', '1 Main St', 'Ballarat', $order->reference(), '$24', '$15']);
});

it('keeps the delivery run behind the admin sign-in', function () {
    auth()->logout();

    $this->get(OrderResource::getUrl('delivery-run', ['drop' => Drop::factory()->create()]))->assertRedirect(route('filament.admin.auth.login'));
});
