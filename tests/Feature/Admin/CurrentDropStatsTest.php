<?php

use App\Checkout\OrderPayments;
use App\Enums\DeliveryMethod;
use App\Filament\Widgets\CurrentDropStats;
use App\Models\User;
use App\Payments\CheckoutSession;
use App\Stock\StockLedger;
use Illuminate\Support\Facades\Mail;
use Tests\Fixtures\StockedDrop;

use function Pest\Livewire\livewire;

it('shows what’s sold, at checkout and left of each item in the current drop', function () {
    Mail::fake();
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));
    $shop = StockedDrop::create(boxes: 5, mince: 10);
    $ledger = app(StockLedger::class);
    $sold = $ledger->reserve($shop->drop, [$shop->box->id => 1, $shop->mince->id => 2], DeliveryMethod::Pickup, null, 'a', now()->addMinutes(31));
    app(OrderPayments::class)->markPaid($sold, new CheckoutSession('cs_1', 'complete', 'paid'));
    $ledger->reserve($shop->drop, [$shop->mince->id => 3], DeliveryMethod::Pickup, null, 'b', now()->addMinutes(31));

    livewire(CurrentDropStats::class)
        ->assertCanSeeTableRecords([$shop->box, $shop->mince])
        ->assertTableColumnStateSet('sold', 1, $shop->box)
        ->assertTableColumnStateSet('left', 4, $shop->box)
        ->assertTableColumnStateSet('sold', 2, $shop->mince)
        ->assertTableColumnStateSet('at_checkout', 3, $shop->mince)
        ->assertTableColumnStateSet('left', 5, $shop->mince)
        ->assertTableColumnStateSet('revenue', '$24', $shop->mince);
});

it('points to creating a drop when there is no current drop', function () {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));

    livewire(CurrentDropStats::class)
        ->assertSee('No drop yet')
        ->assertSee('Create a drop');
});

it('does not offer to create a drop while one is current', function () {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));
    $shop = StockedDrop::create(boxes: 5, mince: 10);

    livewire(CurrentDropStats::class)
        ->assertSee($shop->drop->name)
        ->assertDontSee('Create a drop');
});
