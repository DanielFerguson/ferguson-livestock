<?php

declare(strict_types=1);

use App\Models\Drop;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Sms\OrderConfirmationText;
use Illuminate\Support\Facades\URL;

beforeEach(fn () => URL::useOrigin('https://fergusonlivestock.com.au'));

/**
 * A paid order for these products (name => quantity), loaded as the text needs it.
 *
 * @param  array<string, int>  $items
 * @param  array<string, mixed>  $attributes
 */
function orderOf(Drop $drop, string $customer, array $items, array $attributes = []): Order
{
    $order = Order::factory()->for($drop)->pickup()->create(['customer_name' => $customer, 'phone' => '+61412345678', ...$attributes]);

    foreach ($items as $name => $quantity) {
        OrderItem::factory()->for($order)->for(Product::factory()->create(['name' => $name]))->create(['quantity' => $quantity]);
    }

    return $order->load(['items.product', 'drop']);
}

function weekendDrop(): Drop
{
    return Drop::factory()->open()->create(['delivery_days' => ['2026-11-14', '2026-11-15']]);
}

it('tells a pickup customer what they ordered, and where and when to collect it', function (): void {
    $order = orderOf(weekendDrop(), 'Alec D Carson', ['10kg Beef Box' => 1]);

    $text = OrderConfirmationText::for([$order]);

    expect($text->text)->toBe("Ferguson Livestock: Hi Alec, thanks for your order {$order->reference()}: 1 x 10kg Beef Box. Pick it up from 306 Snake Valley-Chepstowe Rd, Snake Valley, Saturday or Sunday between 10am and 4pm. Map: fergusonlivestock.com.au/farm Questions? Just reply here. - Daniel & Tahlia")
        ->and($text->isGsm())->toBeTrue();
});

it('leaves what was given free off the list, since it wasn’t ordered', function (): void {
    $order = orderOf(weekendDrop(), 'Alec D Carson', ['10kg Beef Box' => 1]);
    OrderItem::factory()->for($order)->for(Product::factory()->create(['name' => '500g Beef Mince']))->free()->create(['quantity' => 2]);

    expect(OrderConfirmationText::for([$order->load('items.product')])->text)
        ->toContain("thanks for your order {$order->reference()}: 1 x 10kg Beef Box. Pick it up from")
        ->not->toContain('Mince');
});

it('tells a delivery customer the night they chose, and leaves the delivery fee off the list', function (): void {
    $drop = weekendDrop();
    $order = orderOf($drop, 'Paul Clarke', ['10kg Beef Box' => 1], ['delivery_method' => 'delivery', 'delivery_day' => '2026-11-15']);
    OrderItem::factory()->for($order)->for(Product::factory()->delivery()->create(['name' => 'Delivery']))->create();

    $text = OrderConfirmationText::for([$order->load('items.product')]);

    expect($text->text)->toBe("Ferguson Livestock: Hi Paul, thanks for your order {$order->reference()}: 1 x 10kg Beef Box. We'll deliver it after 4pm on Sunday, and we'll text you closer to the time. Questions? Just reply here. - Daniel & Tahlia");
});

it('gives both nights to a delivery customer happy with either', function (): void {
    $order = orderOf(weekendDrop(), 'K Rogan', ['10kg Beef Mince Pack · save $60' => 1], ['delivery_method' => 'delivery', 'delivery_day' => null]);

    expect(OrderConfirmationText::for([$order])->text)
        ->toContain('Hi there, thanks for your order')
        ->toContain(': 1 x 10kg Beef Mince Pack. We\'ll deliver it after 4pm on Saturday or Sunday,');
});

it('covers all of someone’s orders in one plain text, greeting them by their name', function (): void {
    $drop = weekendDrop();
    $first = orderOf($drop, 'MR ELVIS GABOYO', ['140–150g Oyster Blade Steak' => 2]);
    $second = orderOf($drop, 'MR ELVIS GABOYO', ['10kg Beef Box' => 1, 'BBQ Sausage Pack (5 × 6) · save $10' => 1]);

    $text = OrderConfirmationText::for([$first, $second]);

    expect($text->text)->toStartWith("Ferguson Livestock: Hi Elvis, thanks for your orders {$first->reference()} and {$second->reference()}: 2 x 140-150g Oyster Blade Steak, 1 x 10kg Beef Box, 1 x BBQ Sausage Pack (5 x 6). Pick them up from")
        ->and($text->isGsm())->toBeTrue();
});

it('greets someone by name only when they gave one', function (string $customer, string $greeting): void {
    expect(OrderConfirmationText::for([orderOf(weekendDrop(), $customer, ['10kg Beef Box' => 1])])->text)->toStartWith("Ferguson Livestock: {$greeting}, thanks");
})->with([
    'a title and an initial' => ['Mrs D Hogenhout', 'Hi there'],
    'an initial' => ['C Perrin', 'Hi there'],
    'a title and a name' => ['Miss Shannon Douglas ', 'Hi Shannon'],
    'capitals and a double space' => ['JUSTIN  BROWN', 'Hi Justin'],
    'a link instead of a name' => ['bit.ly/claim-prize Smith', 'Hi there'],
    'a name with an apostrophe' => ["D'Arcy Smith", "Hi D'Arcy"],
]);

it('sends the map link in pickup texts to the farm on Google Maps', function (): void {
    $this->get('/farm')->assertRedirect(config()->string('shop.location.map_url'));
});
