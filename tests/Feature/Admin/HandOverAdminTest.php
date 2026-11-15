<?php

declare(strict_types=1);

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Drop;
use App\Models\Order;
use App\Models\User;

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
