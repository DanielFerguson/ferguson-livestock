<?php

declare(strict_types=1);

use App\Models\Drop;
use App\Models\Order;
use App\Sms\OrderConfirmationText;
use Illuminate\Support\Facades\URL;

beforeEach(fn () => URL::useOrigin('https://fergusonlivestock.com.au'));

/**
 * A paid pickup order, loaded as the text needs it.
 *
 * @param  array<string, mixed>  $attributes
 */
function orderOf(Drop $drop, string $customer, array $attributes = []): Order
{
    return Order::factory()->for($drop)->pickup()->create(['customer_name' => $customer, 'phone' => '+61412345678', ...$attributes])->load('drop');
}

/**
 * @param  list<string>  $nights
 */
function weekendDrop(array $nights = ['2026-11-14', '2026-11-15']): Drop
{
    return Drop::factory()->open()->create(['delivery_days' => $nights]);
}

it('tells a pickup customer when to collect their order in one text, and links the address and map', function (): void {
    $order = orderOf(weekendDrop(), 'Alec D Carson');

    $text = OrderConfirmationText::for([$order]);

    expect($text->text)->toBe("Ferguson Livestock: Thanks Alec! Pick up order {$order->reference()} Saturday or Sunday, 10am to 4pm. Address & map: https://fergs.au/{$order->access_token}")
        ->and($text->isGsm())->toBeTrue()
        ->and($text->segments())->toBe(1);
});

it('tells a delivery customer the night they chose in one text', function (): void {
    $order = orderOf(weekendDrop(), 'Paul Clarke', ['delivery_method' => 'delivery', 'delivery_day' => '2026-11-15']);

    $text = OrderConfirmationText::for([$order]);

    expect($text->text)->toBe("Ferguson Livestock: Thanks Paul! We'll deliver order {$order->reference()} on Sunday after 4pm. Details: https://fergs.au/{$order->access_token}")
        ->and($text->segments())->toBe(1);
});

it('gives both nights to a delivery customer happy with either', function (): void {
    $order = orderOf(weekendDrop(), 'K Rogan', ['delivery_method' => 'delivery', 'delivery_day' => null]);

    expect(OrderConfirmationText::for([$order])->text)
        ->toContain("Thanks! We'll deliver order {$order->reference()} on Saturday or Sunday after 4pm.");
});

it('covers all of someone’s orders in one text, and links the first order’s page, since that page lists the rest', function (): void {
    $drop = weekendDrop();
    $first = orderOf($drop, 'MR ELVIS GABOYO');
    $second = orderOf($drop, 'MR ELVIS GABOYO');

    $text = OrderConfirmationText::for([$first, $second]);

    expect($text->text)->toBe("Ferguson Livestock: Thanks Elvis! Pick up your 2 orders Saturday or Sunday, 10am to 4pm. Address & map: https://fergs.au/{$first->access_token}")
        ->and($text->segments())->toBe(1);
});

it('thanks someone by name only when they gave one', function (string $customer, string $thanks): void {
    expect(OrderConfirmationText::for([orderOf(weekendDrop(), $customer)])->text)->toStartWith("Ferguson Livestock: {$thanks} Pick up");
})->with([
    'a title and an initial' => ['Mrs D Hogenhout', 'Thanks!'],
    'an initial' => ['C Perrin', 'Thanks!'],
    'a title and a name' => ['Miss Shannon Douglas ', 'Thanks Shannon!'],
    'capitals and a double space' => ['JUSTIN  BROWN', 'Thanks Justin!'],
    'a link instead of a name' => ['bit.ly/claim-prize Smith', 'Thanks!'],
    'a name with an apostrophe' => ["D'Arcy Smith", "Thanks D'Arcy!"],
]);

it('fits the longest name it uses in one text on a two-night drop', function (string $method): void {
    $text = OrderConfirmationText::for([orderOf(weekendDrop(), 'Bartholomew-Wilfreda Smith', ['delivery_method' => $method, 'delivery_day' => null])]);

    expect($text->text)->toContain('Thanks Bartholomew-Wilfreda!')
        ->and($text->segments())->toBe(1);
})->with([
    'pickup' => ['pickup'],
    'delivery on either night' => ['delivery'],
]);

it('drops a name that would take the text past one', function (string $customer, Drop $drop): void {
    $text = OrderConfirmationText::for([orderOf($drop, $customer)]);

    expect($text->text)->toStartWith('Ferguson Livestock: Thanks! Pick up')
        ->and($text->isGsm())->toBeTrue()
        ->and($text->segments())->toBe(1);
})->with([
    'a long name on a three-night drop' => fn (): array => ['Bartholomew-Wilfreda Smith', weekendDrop(['2026-11-13', '2026-11-14', '2026-11-15'])],
    'a name outside the GSM alphabet' => fn (): array => ['Zoë Smith', weekendDrop()],
]);

it('sends the farm’s map link to Google Maps', function (): void {
    $this->get('/farm')->assertRedirect(config()->string('shop.location.map_url'));
});
