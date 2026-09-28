<?php

use App\Enums\DropStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Filament\Resources\Drops\Pages\CreateDrop;
use App\Filament\Resources\Drops\Pages\EditDrop;
use App\Filament\Resources\Drops\Pages\ListDrops;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Payments\PaymentGateway;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\MessageBag;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Tests\Fakes\FakePaymentGateway;

use function Pest\Livewire\livewire;

beforeEach(function () {
    // 1 October 2026 is still standard time (AEST); the drop below opens after the clocks go forward.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00', 'Australia/Melbourne'));

    config(['shop.admin_email' => 'daniel@example.com']);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));

    $this->stripe = (new FakePaymentGateway)->addPrice('price_box', 16000)->addPrice('price_delivery', 1500);
    app()->instance(PaymentGateway::class, $this->stripe);

    $this->box = Product::factory()->box()->create(['name' => '5kg Beef Box']);
    $this->delivery = Product::factory()->delivery()->create(['name' => 'Delivery']);

    $this->undoRepeaterFake = Repeater::fake();
});

afterEach(fn () => ($this->undoRepeaterFake)());

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dropForm(array $overrides = []): array
{
    return array_merge([
        'name' => 'October drop',
        'opens_at' => '2026-10-10 09:00',
        'closes_at' => null,
        'published_at' => true,
        'delivery_days' => [['day' => '2026-10-17']],
        'items' => [
            ['product_id' => Product::where('type', ProductType::Box)->value('id'), 'stripe_price_id' => 'price_box', 'price' => '160', 'quantity' => 5, 'max_per_order' => 1],
            ['product_id' => Product::where('type', ProductType::Delivery)->value('id'), 'stripe_price_id' => 'price_delivery', 'price' => '15'],
        ],
    ], $overrides);
}

/**
 * The form's error messages for a field. Needed where messages contain ':', which Livewire's
 * assertHasErrors would read as rule parameters.
 *
 * @param  Testable<covariant Component>  $component
 * @return list<string>
 */
function formErrors(Testable $component, string $field): array
{
    $errors = $component->errors();

    return $errors instanceof MessageBag
        ? array_values(array_filter($errors->get("data.{$field}"), fn (mixed $message): bool => is_string($message)))
        : [];
}

it('saves a drop entered in Melbourne time as UTC, across the clock change', function () {
    livewire(CreateDrop::class)->fillForm(dropForm())->call('create')->assertHasNoFormErrors();

    $drop = Drop::with('items')->sole();

    expect($drop->opens_at->toIso8601String())->toBe('2026-10-09T22:00:00+00:00')
        ->and($drop->published_at)->not->toBeNull()
        ->and($drop->delivery_days)->toBe(['2026-10-17'])
        ->and($drop->items->firstWhere('product_id', $this->box->id)?->only('price', 'quantity', 'available', 'max_per_order'))
        ->toBe(['price' => 16000, 'quantity' => 5, 'available' => 5, 'max_per_order' => 1])
        ->and($drop->items->firstWhere('product_id', $this->delivery->id)?->only('price', 'quantity', 'available'))
        ->toBe(['price' => 1500, 'quantity' => null, 'available' => null]);
});

it('shows the saved times in Melbourne time', function () {
    $drop = Drop::factory()->create(['opens_at' => '2026-10-09 22:00:00']);

    livewire(EditDrop::class, ['record' => $drop->getRouteKey()])->assertFormSet(['opens_at' => '2026-10-10 09:00']);
});

it('keeps a draft unpublished', function () {
    livewire(CreateDrop::class)->fillForm(dropForm(['published_at' => false]))->call('create')->assertHasNoFormErrors();

    expect(Drop::sole()->published_at)->toBeNull();
});

it('starts a new drop as a draft', function () {
    livewire(CreateDrop::class)->assertFormSet(['published_at' => false, 'announced_at' => false]);
});

it('keeps a draft unpublished when it is edited and saved', function () {
    $drop = Drop::factory()->draft()->create();

    livewire(EditDrop::class, ['record' => $drop->getRouteKey()])
        ->assertFormSet(['published_at' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($drop->refresh()->published_at)->toBeNull();
});

it('announces a draft’s date on the website without publishing it', function () {
    livewire(CreateDrop::class)
        ->fillForm(dropForm(['published_at' => false, 'announced_at' => true, 'items' => []]))
        ->call('create')
        ->assertHasNoFormErrors();

    $drop = Drop::sole();

    expect($drop->published_at)->toBeNull()
        ->and($drop->announced_at)->not->toBeNull()
        ->and($drop->status())->toBe(DropStatus::Announced);
});

it('doesn’t announce a draft unless asked', function () {
    livewire(CreateDrop::class)
        ->fillForm(dropForm(['published_at' => false, 'items' => []]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Drop::sole()->announced_at)->toBeNull();
});

it('stops announcing when the toggle is switched off', function () {
    $drop = Drop::factory()->announced()->create(['opens_at' => '2026-11-13 22:00:00']);

    livewire(EditDrop::class, ['record' => $drop->getRouteKey()])
        ->assertFormSet(['announced_at' => true])
        ->fillForm(['announced_at' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($drop->refresh()->announced_at)->toBeNull();
});

it('offers to announce a draft, but not a published drop', function () {
    livewire(CreateDrop::class)
        ->fillForm(['published_at' => false])
        ->assertFormFieldVisible('announced_at')
        ->fillForm(['published_at' => true])
        ->assertFormFieldHidden('announced_at');
});

it('rejects opening times that daylight saving skips or repeats', function (string $time, string $message) {
    $component = livewire(CreateDrop::class)
        ->fillForm(dropForm(['opens_at' => $time]))
        ->call('create')
        ->assertHasFormErrors(['opens_at']);

    expect(formErrors($component, 'opens_at'))->toContain($message);
})->with([
    'clocks go forward' => ['2026-10-04 02:30', '2:30am doesn’t exist on Sunday 4 October 2026 because the clocks go forward that morning. Pick a time after 3am.'],
    'clocks go back' => ['2027-04-04 02:30', '2:30am happens twice on Sunday 4 April 2027 because the clocks go back that morning. Pick a time before 2am or after 3am.'],
]);

it('rejects a close time before the opening time', function () {
    livewire(CreateDrop::class)
        ->fillForm(dropForm(['closes_at' => '2026-10-10 08:00']))
        ->call('create')
        ->assertHasFormErrors(['closes_at']);
});

it('keeps published drops from overlapping', function () {
    Drop::factory()->create(['name' => 'Spring drop', 'opens_at' => '2026-10-09 22:00:00']);

    $component = livewire(CreateDrop::class)
        ->fillForm(dropForm())
        ->call('create')
        ->assertHasFormErrors(['opens_at']);

    expect(formErrors($component, 'opens_at'))->toContain('This overlaps “Spring drop”, which opens at 9:00am Sat 10 Oct. Only one drop can be open at a time.');
});

it('lets a draft share a time with another drop', function () {
    Drop::factory()->create(['opens_at' => '2026-10-09 22:00:00']);

    livewire(CreateDrop::class)->fillForm(dropForm(['published_at' => false]))->call('create')->assertHasNoFormErrors();
});

it('checks each Stripe price before saving', function (Closure $stripe, string $message) {
    $stripe($this->stripe);

    livewire(CreateDrop::class)
        ->fillForm(dropForm())
        ->call('create')
        ->assertHasFormErrors(['items.0.stripe_price_id' => $message]);
})->with([
    'missing' => [fn (FakePaymentGateway $stripe) => app()->instance(PaymentGateway::class, (new FakePaymentGateway)->addPrice('price_delivery', 1500)), 'Stripe has no price with this ID. Check it was copied from the right Stripe account (test or live).'],
    'archived' => [fn (FakePaymentGateway $stripe) => $stripe->addPrice('price_box', 16000, active: false), 'This Stripe price is archived. Unarchive it or use an active price.'],
    'subscription' => [fn (FakePaymentGateway $stripe) => $stripe->addPrice('price_box', 16000, type: 'recurring'), 'This is a subscription price. Drops need a one-time price.'],
    'US dollars' => [fn (FakePaymentGateway $stripe) => $stripe->addPrice('price_box', 16000, currency: 'usd'), 'This price is in USD. Drops are charged in AUD.'],
    'different amount' => [fn (FakePaymentGateway $stripe) => $stripe->addPrice('price_box', 15000), 'Stripe charges $150 for this price, but the drop says $160.'],
]);

it('fills in the price from Stripe when a price ID is pasted', function () {
    livewire(CreateDrop::class)
        ->fillForm(dropForm(['items' => [['product_id' => $this->box->id, 'price' => null, 'quantity' => 5]]]))
        ->set('data.items.0.stripe_price_id', 'price_box')
        ->assertFormSet(['items.0.price' => '160']);
});

it('changes stock on a live drop without losing what customers are holding', function () {
    $drop = Drop::factory()->open()->create();
    $item = DropItem::factory()->for($drop)->for($this->box)->create(['stripe_price_id' => 'price_box', 'price' => 16000, 'quantity' => 5, 'available' => 3]);

    livewire(EditDrop::class, ['record' => $drop->getRouteKey()])
        ->fillForm(['items' => ["record-{$item->id}" => ['product_id' => $this->box->id, 'stripe_price_id' => 'price_box', 'price' => '160', 'quantity' => 4, 'max_per_order' => 10]]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($item->refresh()->only('quantity', 'available'))->toBe(['quantity' => 4, 'available' => 2]);
});

it('won’t set stock below what customers are holding', function () {
    $drop = Drop::factory()->open()->create();
    $item = DropItem::factory()->for($drop)->for($this->box)->create(['stripe_price_id' => 'price_box', 'price' => 16000, 'quantity' => 5, 'available' => 3]);

    livewire(EditDrop::class, ['record' => $drop->getRouteKey()])
        ->fillForm(['items' => ["record-{$item->id}" => ['product_id' => $this->box->id, 'stripe_price_id' => 'price_box', 'price' => '160', 'quantity' => 1, 'max_per_order' => 10]]])
        ->call('save')
        ->assertHasFormErrors(["items.record-{$item->id}.quantity" => 'At least 2 — that many are already held or sold.']);

    expect($item->refresh()->only('quantity', 'available'))->toBe(['quantity' => 5, 'available' => 3]);
});

it('won’t remove a product customers are holding', function () {
    $drop = Drop::factory()->open()->create();
    DropItem::factory()->for($drop)->for($this->box)->create(['stripe_price_id' => 'price_box', 'price' => 16000, 'quantity' => 5, 'available' => 3]);

    livewire(EditDrop::class, ['record' => $drop->getRouteKey()])
        ->fillForm(['items' => []])
        ->call('save')
        ->assertNotified('5kg Beef Box can’t be removed');

    expect($drop->items()->count())->toBe(1);
});

it('won’t remove a product that’s on an order, even one that was abandoned', function () {
    $drop = Drop::factory()->open()->create();
    $item = DropItem::factory()->for($drop)->for($this->box)->create(['stripe_price_id' => 'price_box', 'price' => 16000, 'quantity' => 5]);
    OrderItem::factory()->for(Order::factory()->for($drop)->pending()->create(['status' => OrderStatus::Expired]))->create(['drop_item_id' => $item->id]);

    livewire(EditDrop::class, ['record' => $drop->getRouteKey()])
        ->fillForm(['items' => []])
        ->call('save')
        ->assertNotified('5kg Beef Box can’t be removed');

    expect($drop->items()->count())->toBe(1);
});

it('only lets a drop be deleted before anyone has ordered from it', function () {
    $drop = Drop::factory()->create();
    livewire(EditDrop::class, ['record' => $drop->getRouteKey()])->assertActionVisible('delete');

    Order::factory()->for($drop)->pending()->create(['status' => OrderStatus::Expired]);

    livewire(EditDrop::class, ['record' => $drop->getRouteKey()])->assertActionHidden('delete');
});

it('lets an announced draft be deleted', function () {
    $drop = Drop::factory()->announced()->create();

    livewire(EditDrop::class, ['record' => $drop->getRouteKey()])->assertActionVisible('delete');
});

it('stops announcing the date once the draft is published', function () {
    $drop = Drop::factory()->announced()->create(['opens_at' => '2026-11-13 22:00:00']);

    livewire(EditDrop::class, ['record' => $drop->getRouteKey()])
        ->assertFormSet(['published_at' => false, 'announced_at' => true])
        ->fillForm(['published_at' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Drop::announced())->toBeNull()
        ->and($drop->refresh()->status())->toBe(DropStatus::Scheduled);
});

it('closes a live drop from the list', function () {
    $drop = Drop::factory()->open()->withStock()->create();

    livewire(ListDrops::class)->callAction(TestAction::make('closeNow')->table($drop));

    expect($drop->refresh()->closed_at)->not->toBeNull();
});

it('duplicates a drop as a draft', function () {
    $drop = Drop::factory()->closed()->withStock()->create(['name' => 'Autumn drop']);

    livewire(EditDrop::class, ['record' => $drop->getRouteKey()])->callAction('duplicate');

    expect(Drop::where('name', 'Autumn drop (copy)')->sole()->published_at)->toBeNull();
});

it('runs the pre-flight check on demand and shows what to fix', function () {
    $drop = Drop::factory()->create(['opens_at' => now()->addDay()]);

    livewire(EditDrop::class, ['record' => $drop->getRouteKey()])
        ->callAction('runPreflight')
        ->assertNotified('Fix before opening');

    expect($drop->refresh()->preflight_report['passed'] ?? null)->toBeFalse();
});

it('lists drops with Melbourne times and their status', function () {
    $drop = Drop::factory()->withStock()->create(['name' => 'October drop', 'opens_at' => '2026-10-09 22:00:00']);

    livewire(ListDrops::class)
        ->assertCanSeeTableRecords([$drop])
        ->assertSee('Sat 10 Oct 2026, 9:00am AEDT')
        ->assertSee('Scheduled');
});

it('explains where a box that borrows stock gets it from', function () {
    $large = Product::factory()->drawsStockFrom($this->box, 2)->create(['name' => '10kg Beef Box']);
    $drop = Drop::factory()->create();
    DropItem::factory()->for($drop)->for($this->box)->create();
    DropItem::factory()->for($drop)->for($large)->withoutStock()->create();

    livewire(EditDrop::class, ['record' => $drop->id])
        ->assertSee('Packed from the 5kg Beef Box stock, 2 per box.');
});
