# A Customer's Orders at Handover Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** When a customer has more than one order on a drop, the admin shows and hands over all of them together, and the orders resource works on a phone: To pick up and To deliver tabs, a slim list, items first on the order page.

**Architecture:** `App\Support\CustomerOrders` groups a drop's placed orders by customer (shared phone, email or name, chained). `App\Actions\Orders\HandOverOrders` fulfils a set of orders through `OrderPayments`' compare-and-set transitions, recording a cash order's cash first. The Filament orders list, the order page and the Mark fulfilled action all use these two, so they always agree. Nothing is stored or merged: grouping is worked out on each request.

**Tech Stack:** Laravel 13, PHP 8.5, Postgres, Filament 5.9, Livewire 4, Pest 5, Tailwind 4 (admin theme).

**Spec:** `docs/superpowers/specs/2026-11-15-customer-orders-at-handover-design.md`

**Conventions to follow:** read `AGENTS.md`. PHPDoc over inline comments, curly braces always, explicit types, `final readonly` for plain classes. Owner-facing text uses curly apostrophes (’). Admin-only CSS goes in `resources/css/filament/admin/theme.css` as `fl-*` classes built with `@apply`, with a comment naming the view that uses them. Run tests with `php artisan test --compact <file>`, one run at a time: two runs at once share the test database and fail with phantom errors (use a private `DB_DATABASE` if you must run in parallel). Work goes straight to `main`. Commit after each task, but **don't push**: a push to `main` deploys to the live site.

**Decisions already made (don't revisit):**
- Orders are one customer's when they share a phone (compared as E.164), an email (ignoring case) or a name (ignoring case and spaces), chained. Same-name strangers get grouped; that's accepted.
- Only placed orders count (`OrderStatus::placed()`: Paid, Processing, Fulfilled, CashOwed), on the same drop.
- To pick up and To deliver replace To fulfil. Both cover `OrderStatus::toFulfil()` (Paid, CashOwed).
- A lone Paid order with nothing else to hand over fulfils in one tap. Otherwise a pop-up lists every order of theirs, with those still to hand over ticked. Ticking a cash order records its cash and fulfils it.
- Order, Customer and Delivery sections are collapsed on every screen size. Item lines lose their prices.
- A list row offers Mark fulfilled only for its own order (Paid or Cash owed). The order page offers it while *any* of the customer's orders is still to hand over.

---

### Task 1: Group a drop's orders by customer

**Files:**
- Create: `app/Support/CustomerOrders.php`
- Test: `tests/Feature/Support/CustomerOrdersTest.php`

**Step 1: Create the test file**

Run: `php artisan make:test --pest Support/CustomerOrdersTest --no-interaction`

Replace its contents with:

```php
<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\Drop;
use App\Models\Order;
use App\Support\CustomerOrders;

it('puts orders together when they share a phone, email or name', function (array $first, array $second): void {
    $drop = Drop::factory()->open()->create();
    $one = Order::factory()->for($drop)->create($first);
    $two = Order::factory()->for($drop)->create($second);

    $orders = CustomerOrders::onDrop($drop->id)->of($one);

    expect($orders->modelKeys())->toBe([$one->id, $two->id]);
})->with([
    'the same mobile, however it was written' => [
        ['phone' => '+61412345678', 'email' => 'sam@example.test', 'customer_name' => 'Sam Buyer'],
        ['phone' => '0412 345 678', 'email' => 'alex@example.test', 'customer_name' => 'Alex Buyer'],
    ],
    'the same email in another case' => [
        ['phone' => '+61412345678', 'email' => 'sam@example.test', 'customer_name' => 'Sam Buyer'],
        ['phone' => '+61487654321', 'email' => 'Sam@Example.test', 'customer_name' => 'Alex Buyer'],
    ],
    'the same name, ignoring case and spaces' => [
        ['phone' => '+61412345678', 'email' => 'sam@example.test', 'customer_name' => 'Sam Buyer'],
        ['phone' => null, 'email' => null, 'customer_name' => ' sam  BUYER '],
    ],
]);

it('keeps apart customers who share nothing', function (): void {
    $drop = Drop::factory()->open()->create();
    $sam = Order::factory()->for($drop)->create(['phone' => '+61412345678', 'email' => 'sam@example.test', 'customer_name' => 'Sam Buyer']);
    Order::factory()->for($drop)->create(['phone' => '+61487654321', 'email' => 'alex@example.test', 'customer_name' => 'Alex Buyer']);

    expect(CustomerOrders::onDrop($drop->id)->of($sam)->modelKeys())->toBe([$sam->id]);
});

it('never puts orders together over details that are blank', function (): void {
    $drop = Drop::factory()->open()->create();
    $first = Order::factory()->for($drop)->cashOwed()->create(['phone' => null, 'customer_name' => null]);
    Order::factory()->for($drop)->cashOwed()->create(['phone' => '', 'email' => '', 'customer_name' => '  ']);

    expect(CustomerOrders::onDrop($drop->id)->of($first)->modelKeys())->toBe([$first->id]);
});

it('puts together orders linked only through another of the customer’s orders', function (): void {
    $drop = Drop::factory()->open()->create();
    $byName = Order::factory()->for($drop)->cashOwed()->create(['phone' => null, 'customer_name' => 'Aaron Baker']);
    $byPhone = Order::factory()->for($drop)->create(['phone' => '+61437984526', 'email' => 'a.baker@example.test', 'customer_name' => 'A Baker']);
    $both = Order::factory()->for($drop)->create(['phone' => '+61437984526', 'email' => 'aaron@example.test', 'customer_name' => 'Aaron Baker']);

    expect(CustomerOrders::onDrop($drop->id)->of($byName)->modelKeys())->toBe([$byName->id, $byPhone->id, $both->id]);
});

it('starts with the order asked about, then the rest oldest first', function (): void {
    $drop = Drop::factory()->open()->create();
    $first = Order::factory()->for($drop)->create(['customer_name' => 'Sam Buyer']);
    $second = Order::factory()->for($drop)->create(['customer_name' => 'Sam Buyer']);
    $third = Order::factory()->for($drop)->create(['customer_name' => 'Sam Buyer']);

    expect(CustomerOrders::onDrop($drop->id)->of($second)->modelKeys())->toBe([$second->id, $first->id, $third->id]);
});

it('leaves out the customer’s orders on other drops', function (): void {
    $drop = Drop::factory()->open()->create();
    $order = Order::factory()->for($drop)->create(['customer_name' => 'Sam Buyer']);
    Order::factory()->for(Drop::factory()->open())->create(['customer_name' => 'Sam Buyer']);

    expect(CustomerOrders::onDrop($drop->id)->of($order)->modelKeys())->toBe([$order->id]);
});

it('leaves out checkouts that didn’t become orders', function (OrderStatus $status): void {
    $drop = Drop::factory()->open()->create();
    $order = Order::factory()->for($drop)->create(['customer_name' => 'Sam Buyer']);
    Order::factory()->for($drop)->create(['customer_name' => 'Sam Buyer', 'status' => $status]);

    expect(CustomerOrders::onDrop($drop->id)->of($order)->modelKeys())->toBe([$order->id]);
})->with([
    'still at checkout' => OrderStatus::Pending,
    'abandoned' => OrderStatus::Expired,
    'payment failed' => OrderStatus::Failed,
]);

it('gives a checkout that didn’t become an order on its own', function (): void {
    $drop = Drop::factory()->open()->create();
    Order::factory()->for($drop)->create(['customer_name' => 'Sam Buyer']);
    $abandoned = Order::factory()->for($drop)->create(['customer_name' => 'Sam Buyer', 'status' => OrderStatus::Expired]);

    expect(CustomerOrders::onDrop($drop->id)->of($abandoned)->modelKeys())->toBe([$abandoned->id]);
});
```

**Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Support/CustomerOrdersTest.php`
Expected: FAIL, `Class "App\Support\CustomerOrders" not found`.

**Step 3: Write the class**

Run: `php artisan make:class Support/CustomerOrders --no-interaction`, then replace its contents with:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who placed which of a drop's orders. Some customers order more than once on a drop (an extra box, something they
 * forgot), and all of their orders have to be handed over together.
 *
 * Two orders are the same customer's when they share a phone number, an email or a name, each compared however it
 * was typed. That chains: two orders matching by name, one of which matches a third by phone, are all one
 * customer's. Cash orders often have only a name, so names count too. Two people who share a name are put together,
 * which is why every order is shown by its reference wherever they're grouped.
 */
final readonly class CustomerOrders
{
    /**
     * @param  list<Collection<int, Order>>  $customers  each customer's orders, oldest first
     */
    private function __construct(private array $customers) {}

    /**
     * The drop's placed orders (paid, clearing, fulfilled or owed in cash), grouped by customer.
     *
     * @param  list<string>  $with  relations to load on each order
     */
    public static function onDrop(int $dropId, array $with = []): self
    {
        $orders = Order::query()
            ->where('drop_id', $dropId)
            ->whereIn('status', OrderStatus::placed())
            ->with($with)
            ->oldest()
            ->oldest('id')
            ->get();

        /** @var array<int, array{keys: list<string>, ids: list<int>}> $customers */
        $customers = [];

        foreach ($orders as $order) {
            $customer = ['keys' => self::keys($order), 'ids' => [$order->id]];

            // An order can link customers that looked like two people until now, so every match is merged in.
            foreach ($customers as $index => $other) {
                if (array_intersect($customer['keys'], $other['keys']) !== []) {
                    $customer = ['keys' => [...$other['keys'], ...$customer['keys']], 'ids' => [...$other['ids'], ...$customer['ids']]];
                    unset($customers[$index]);
                }
            }

            $customers[] = $customer;
        }

        return new self(array_values(array_map(
            fn (array $customer): Collection => $orders->whereIn('id', $customer['ids'])->values(),
            $customers,
        )));
    }

    /**
     * The order's customer's placed orders on its drop, this one first and the rest oldest first. An order that
     * wasn't placed (still at checkout, abandoned or failed) is on its own.
     *
     * @return Collection<int, Order>
     */
    public function of(Order $order): Collection
    {
        foreach ($this->customers as $orders) {
            if ($orders->contains('id', $order->id)) {
                return $orders->sortBy(fn (Order $other): int => $other->is($order) ? 0 : 1)->values();
            }
        }

        return new Collection([$order]);
    }

    /**
     * What ties an order to its customer's other orders: its phone number, email and name, each written one way
     * however it was typed. Blank ones are left out, so they never match.
     *
     * @return list<string>
     */
    private static function keys(Order $order): array
    {
        $phone = trim($order->phone ?? '');
        $email = mb_strtolower(trim($order->email ?? ''));
        $name = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $order->customer_name ?? '')));

        return array_values(array_filter([
            $phone === '' ? null : 'phone:'.(AustralianMobile::toE164($phone) ?? $phone),
            $email === '' ? null : "email:{$email}",
            $name === '' ? null : "name:{$name}",
        ]));
    }
}
```

**Step 4: Run the tests to see them pass**

Run: `php artisan test --compact tests/Feature/Support/CustomerOrdersTest.php`
Expected: PASS (10 tests).

**Step 5: Format, analyse and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/Support/CustomerOrders.php --memory-limit=2G --no-progress
git add app/Support/CustomerOrders.php tests/Feature/Support/CustomerOrdersTest.php
git commit -m "Work out which of a drop's orders belong to the same customer"
```

---

### Task 2: Hand orders over

**Files:**
- Create: `app/Actions/Orders/HandOverOrders.php`
- Test: `tests/Feature/Orders/HandOverOrdersTest.php`

**Step 1: Create the test file**

Run: `php artisan make:test --pest Orders/HandOverOrdersTest --no-interaction`

Replace its contents with:

```php
<?php

declare(strict_types=1);

use App\Actions\Orders\HandOverOrders;
use App\Enums\OrderStatus;
use App\Models\Order;

it('marks a paid order fulfilled', function (): void {
    $order = Order::factory()->create();

    $notHandedOver = app(HandOverOrders::class)([$order]);

    expect($notHandedOver)->toBe([])
        ->and($order->refresh())->status->toBe(OrderStatus::Fulfilled)->fulfilled_at->not->toBeNull();
});

it('records the cash for a cash order, then marks it fulfilled', function (): void {
    $order = Order::factory()->cashOwed()->create();

    app(HandOverOrders::class)([$order]);

    expect($order->refresh())
        ->status->toBe(OrderStatus::Fulfilled)
        ->paid_at->not->toBeNull()
        ->fulfilled_at->not->toBeNull();
});

it('leaves an order handed over from another phone as it was, and says so', function (): void {
    $order = Order::factory()->create();
    Order::query()->whereKey($order->id)->update(['status' => OrderStatus::Fulfilled, 'fulfilled_at' => '2026-11-14 01:00:00']);

    $notHandedOver = app(HandOverOrders::class)([$order]);

    expect(array_map(fn (Order $left): int => $left->id, $notHandedOver))->toBe([$order->id])
        ->and($order->refresh()->fulfilled_at?->toDateTimeString())->toBe('2026-11-14 01:00:00');
});

it('leaves a cash order cancelled in the meantime unpaid, and says so', function (): void {
    $order = Order::factory()->cashOwed()->create();
    Order::query()->whereKey($order->id)->update(['status' => OrderStatus::Expired]);

    $notHandedOver = app(HandOverOrders::class)([$order]);

    expect(array_map(fn (Order $left): int => $left->id, $notHandedOver))->toBe([$order->id])
        ->and($order->refresh())->status->toBe(OrderStatus::Expired)->paid_at->toBeNull();
});
```

**Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Orders/HandOverOrdersTest.php`
Expected: FAIL, `Class "App\Actions\Orders\HandOverOrders" not found`.

**Step 3: Write the action**

Run: `php artisan make:class Actions/Orders/HandOverOrders --no-interaction`, then replace its contents with:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Checkout\OrderPayments;
use App\Enums\OrderStatus;
use App\Models\Order;

/**
 * Hands orders over at pickup or on the doorstep: each is marked fulfilled, a cash order having its cash recorded
 * first. Every step is OrderPayments' compare-and-set on the status, so an order that changed since it was shown
 * (handed over from another phone, or a cash order cancelled) is left as it now is and given back.
 */
final readonly class HandOverOrders
{
    public function __construct(private OrderPayments $payments) {}

    /**
     * @param  iterable<Order>  $orders
     * @return list<Order> the orders that couldn't be handed over
     */
    public function __invoke(iterable $orders): array
    {
        $notHandedOver = [];

        foreach ($orders as $order) {
            if ($order->status === OrderStatus::CashOwed) {
                $this->payments->markCashReceived($order);
            }

            if (! $this->payments->markFulfilled($order)) {
                $notHandedOver[] = $order;
            }
        }

        return $notHandedOver;
    }
}
```

`markCashReceived()` and `markFulfilled()` both refresh `$order`, so if the cash was recorded elsewhere meanwhile, the order still gets fulfilled. A cancelled one fails both and is returned.

**Step 4: Run the tests to see them pass**

Run: `php artisan test --compact tests/Feature/Orders/HandOverOrdersTest.php`
Expected: PASS (4 tests).

**Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/Orders/HandOverOrders.php tests/Feature/Orders/HandOverOrdersTest.php
git commit -m "Hand over orders, recording a cash order's cash first"
```

---

### Task 3: The orders list

To pick up and To deliver replace To fulfil. To deliver comes in delivery-run order. On a phone only Order, Status and Customer show. Under the customer's name: "2 orders" when they have more than one on the drop, and a delivery's night and suburb.

**Files:**
- Modify: `app/Models/Order.php` (add `deliverySummary()` after `deliveryNightShort()`)
- Modify: `app/Filament/Resources/Orders/Pages/ListOrders.php`
- Modify: `app/Filament/Resources/Orders/Tables/OrdersTable.php`
- Modify: `tests/Feature/Admin/OrderResourceTest.php`
- Create: `tests/Feature/Admin/HandOverAdminTest.php`

**Step 1: Update the tab tests**

In `tests/Feature/Admin/OrderResourceTest.php`, delete the `'to fulfil' => ['to-fulfil', ['paid', 'cash owed']],` row from the `shows the orders in each tab` dataset. That tab no longer exists; the new test below covers it. Then add these two tests after that one:

```php
it('splits the orders still to hand over into pickups and deliveries', function (string $tab, array $shown): void {
    $drop = Drop::factory()->open()->create();
    $orders = [
        'pickup' => Order::factory()->for($drop)->pickup()->create(),
        'cash pickup' => Order::factory()->for($drop)->pickup()->cashOwed()->create(),
        'fulfilled pickup' => Order::factory()->for($drop)->pickup()->create(['status' => OrderStatus::Fulfilled, 'fulfilled_at' => now()]),
        'delivery' => Order::factory()->for($drop)->create(),
        'cash delivery' => Order::factory()->for($drop)->cashOwed()->create(),
        'clearing delivery' => Order::factory()->for($drop)->create(['status' => OrderStatus::Processing]),
    ];

    livewire(ListOrders::class)
        ->set('activeTab', $tab)
        ->assertCanSeeTableRecords(array_values(Arr::only($orders, $shown)))
        ->assertCanNotSeeTableRecords(array_values(Arr::except($orders, $shown)));
})->with([
    'to pick up' => ['to-pick-up', ['pickup', 'cash pickup']],
    'to deliver' => ['to-deliver', ['delivery', 'cash delivery']],
]);

it('lists the deliveries still to go out in delivery-run order', function (): void {
    $drop = Drop::factory()->open()->create(['delivery_days' => ['2026-11-14', '2026-11-15']]);
    $sundayBallarat = paidOrder($drop, 'Ballarat', '3350', ['delivery_day' => '2026-11-15']);
    $saturdayCreswick = paidOrder($drop, 'Creswick', '3363', ['delivery_day' => '2026-11-14']);
    $eitherNight = paidOrder($drop, 'Ballarat', '3350', ['delivery_day' => null]);
    $saturdayBallarat = paidOrder($drop, 'Ballarat', '3350', ['delivery_day' => '2026-11-14']);

    livewire(ListOrders::class)
        ->set('activeTab', 'to-deliver')
        ->assertCanSeeTableRecords([$saturdayBallarat, $saturdayCreswick, $sundayBallarat, $eitherNight], inOrder: true);
});
```

The orders are created out of order on purpose, so neither the default newest-first sort nor its reverse matches.

**Step 2: Write the customer-note test**

Run: `php artisan make:test --pest Admin/HandOverAdminTest --no-interaction`

Replace its contents with (later tasks add more tests to this file):

```php
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

    livewire(ListOrders::class)
        ->assertTableColumnHasDescription('customer_name', '2 orders · Sat 14 Nov · Ballarat', $delivery)
        ->assertTableColumnHasDescription('customer_name', null, $alone);
});
```

**Step 3: Run them to see them fail**

Run: `php artisan test --compact tests/Feature/Admin/OrderResourceTest.php tests/Feature/Admin/HandOverAdminTest.php`
Expected: FAIL. The split test can't find the `to-pick-up` / `to-deliver` tabs, the sort test lists newest first, and the description is the city alone or null.

**Step 4: Add `Order::deliverySummary()`**

In `app/Models/Order.php`, after `deliveryNightShort()`:

```php
    /**
     * How the order goes out, as the farm's lists put it: "Farm pickup", or "Delivery, Sat 14 Nov".
     */
    public function deliverySummary(): string
    {
        $night = $this->deliveryNightShort();

        return $night === null ? 'Farm pickup' : "Delivery, {$night}";
    }
```

**Step 5: Rewrite the tabs and add the per-request count**

In `app/Filament/Resources/Orders/Pages/ListOrders.php`:

- Add imports: `use App\Enums\DeliveryMethod;` and `use App\Support\CustomerOrders;`.
- Add this property under `$resource`:

```php
    /**
     * Each listed drop's orders by customer, worked out once a request rather than once a row. Livewire keeps only
     * public properties between requests, so this starts afresh each time.
     *
     * @var array<int, CustomerOrders>
     */
    private array $customerOrders = [];
```

- In `getTabs()`, replace the `'to-fulfil' => …` line with:

```php
            'to-pick-up' => $this->toHandOverTab('To pick up', DeliveryMethod::Pickup),
            'to-deliver' => $this->toHandOverTab('To deliver', DeliveryMethod::Delivery),
```

- Add after `getTabs()`:

```php
    /**
     * How many orders the order's customer has on its drop, counting this one.
     */
    public function customerOrderCount(Order $order): int
    {
        $this->customerOrders[$order->drop_id] ??= CustomerOrders::onDrop($order->drop_id);

        return $this->customerOrders[$order->drop_id]->of($order)->count();
    }
```

- Add after `countedStatusTab()`:

```php
    /**
     * Orders still to hand over by pickup or by delivery: paid ones, and cash ones put aside for friends, with a count
     * of them when there are any. Deliveries come in delivery-run order: by night, either-night ones last, then by
     * suburb.
     */
    private function toHandOverTab(string $label, DeliveryMethod $method): Tab
    {
        $toHandOver = fn (Builder $query): Builder => $query->whereIn('status', OrderStatus::toFulfil())->where('delivery_method', $method);

        return Tab::make($label)
            ->modifyQueryUsing(fn (Builder $query): Builder => $method === DeliveryMethod::Delivery
                ? $toHandOver($query)->orderByRaw('delivery_day asc nulls last')->orderByRaw("lower(shipping_address->>'city')")
                : $toHandOver($query))
            ->badge(function () use ($toHandOver): ?int {
                $count = $toHandOver(Order::query())->count();

                return $count > 0 ? $count : null;
            });
    }
```

The tab's ordering runs before the table's default `created_at desc` sort (Filament applies tab scopes in `getQuery()` and sorting afterwards), so it takes precedence. The sidebar badge in `OrderResource::getNavigationBadge()` is unchanged.

**Step 6: Slim the table down and add the note**

In `app/Filament/Resources/Orders/Tables/OrdersTable.php`:

- Add imports: `use App\Enums\OrderStatus;` and `use App\Filament\Resources\Orders\Pages\ListOrders;`.
- `created_at` column: append `->visibleFrom('md')`.
- `customer_name` column: append

```php
                    ->description(fn (Order $record, ListOrders $livewire): ?string => self::customerNote($record, $livewire->customerOrderCount($record))),
```

- `delivery` column: change its state to `->state(fn (Order $record): string => $record->deliverySummary())` and append `->visibleFrom('md')`.
- `total` column: append `->visibleFrom('md')`.
- Add at the bottom of the class:

```php
    /**
     * What to know at a glance, under the customer's name: "2 orders" when they have more than one on the drop, and
     * a delivery's night and suburb, since the Delivery column doesn't fit on a phone.
     */
    private static function customerNote(Order $order, int $ordersOnDrop): ?string
    {
        $note = array_filter([
            $ordersOnDrop > 1 ? "{$ordersOnDrop} orders" : null,
            $order->deliveryNightShort(),
            $order->delivery_method === DeliveryMethod::Delivery ? ($order->shipping_address['city'] ?? null) : null,
        ]);

        return $note === [] ? null : implode(' · ', $note);
    }
```

On desktop, a delivery's night and suburb now show twice, under the name and in the Delivery column. That's accepted: the list is mostly used on a phone.

**Step 7: Run the tests to see them pass**

Run: `php artisan test --compact tests/Feature/Admin/OrderResourceTest.php tests/Feature/Admin/HandOverAdminTest.php`
Expected: PASS.

**Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Order.php app/Filament/Resources/Orders/Pages/ListOrders.php app/Filament/Resources/Orders/Tables/OrdersTable.php tests/Feature/Admin/OrderResourceTest.php tests/Feature/Admin/HandOverAdminTest.php
git commit -m "Split the orders to hand over into pickups and deliveries, slim the list down for a phone, and say when a customer has more than one order"
```

---

### Task 4: Mark fulfilled hands over everything the customer has on the drop

**Files:**
- Modify: `app/Models/Order.php` (add `fulfilledDay()` after `deliverySummary()`)
- Modify: `app/Filament/Resources/Orders/Actions/OrderActions.php` (`markFulfilled()` and new private helpers)
- Modify: `app/Filament/Resources/Orders/Tables/OrdersTable.php` (row visibility)
- Test: `tests/Feature/Admin/HandOverAdminTest.php`

**Step 1: Write the tests**

Add to the imports of `tests/Feature/Admin/HandOverAdminTest.php`:

```php
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use Filament\Actions\Testing\TestAction;
```

Append:

```php
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
        ->assertSee([$forgotten->reference().' · collect $110 cash', $collected->reference().' · already handed over Sat 14 Nov']);

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
        ->and($cash->refresh())->status->toBe(OrderStatus::Fulfilled)->paid_at->not->toBeNull()
        ->and($notToday->refresh()->status)->toBe(OrderStatus::Paid);
});

it('says which order had changed when it was handed over from another phone meanwhile', function (): void {
    $drop = Drop::factory()->open()->create();
    $opened = Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Mikele Linke']);
    $elsewhere = Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Mikele Linke']);
    $page = livewire(ViewOrder::class, ['record' => $opened->id])->mountAction('markFulfilled');
    Order::query()->whereKey($elsewhere->id)->update(['status' => OrderStatus::Fulfilled, 'fulfilled_at' => now()]);

    $page->callMountedAction()->assertNotified('1 of 2 marked fulfilled');

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
```

`fulfilled_at` 01:00 UTC on 14 November is midday in Melbourne, so it reads "Sat 14 Nov" in the shop's timezone.

**Step 2: Run them to see them fail**

Run: `php artisan test --compact tests/Feature/Admin/HandOverAdminTest.php`
Expected: FAIL. The current action has no pop-up, only fulfils the opened order, and is hidden on fulfilled and cash-owed orders.

**Step 3: Add `Order::fulfilledDay()`**

In `app/Models/Order.php`, after `deliverySummary()`:

```php
    /**
     * The day it was handed over, as the farm's lists put it: "Sat 14 Nov". Null until it's fulfilled.
     */
    public function fulfilledDay(): ?string
    {
        return $this->fulfilled_at?->setTimezone(config()->string('shop.timezone'))->format('D j M');
    }
```

**Step 4: Rewrite `OrderActions::markFulfilled()`**

In `app/Filament/Resources/Orders/Actions/OrderActions.php`, add imports:

```php
use App\Actions\Orders\HandOverOrders;
use App\Support\CustomerOrders;
use Filament\Forms\Components\CheckboxList;
use Illuminate\Support\Arr;
```

Replace `markFulfilled()` with:

```php
    /**
     * Hands over everything the customer has on the order's drop. A paid order on its own goes in one tap, as it
     * always has. When they have more than one order to hand over, or cash to collect, it asks first, listing every
     * order of theirs with those still to go ticked: the list is the reminder that there's more than the order that
     * was opened. A ticked cash order has its cash recorded as it's handed over.
     */
    public static function markFulfilled(): Action
    {
        return Action::make('markFulfilled')
            ->label('Mark fulfilled')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->visible(fn (Order $record): bool => self::toHandOver($record)->isNotEmpty())
            ->modalHidden(function (Order $record): bool {
                $toHandOver = self::toHandOver($record);

                return $toHandOver->count() === 1 && $toHandOver->sole()->status === OrderStatus::Paid;
            })
            ->modalHeading(function (Order $record): string {
                $whose = $record->firstName() === null ? 'their' : "{$record->firstName()}’s";

                return "Hand over {$whose} ".str('order')->plural(self::customerOrders($record)->count()).'?';
            })
            ->modalSubmitActionLabel('Mark fulfilled')
            ->schema([
                CheckboxList::make('orders')
                    ->hiddenLabel()
                    ->options(fn (Order $record): array => self::customerOrders($record)
                        ->mapWithKeys(fn (Order $order): array => [$order->id => self::handOverLabel($order)])
                        ->all())
                    ->descriptions(fn (Order $record): array => self::customerOrders($record)
                        ->load('items.product')
                        ->mapWithKeys(fn (Order $order): array => [$order->id => DeliveryRun::items($order)])
                        ->all())
                    ->disableOptionWhen(fn (Order $record, int|string $value): bool => ! self::toHandOver($record)->contains('id', (int) $value))
                    // Any of the customer's orders may come back ticked, not just those ready when this opened, so one
                    // handed over from another phone meanwhile is reported rather than refused.
                    ->in(fn (Order $record): array => self::customerOrders($record)->modelKeys())
                    ->default(fn (Order $record): array => self::toHandOver($record)->modelKeys())
                    ->required(),
            ])
            ->action(function (array $data, Order $record, HandOverOrders $handOver): void {
                $ticked = is_array($data['orders'] ?? null) ? $data['orders'] : [];
                $orders = self::customerOrders($record)->whereIn('id', $ticked);

                $notHandedOver = $handOver($orders);
                $record->refresh();

                self::notifyHandedOver($orders->count() - count($notHandedOver), $notHandedOver);
            });
    }
```

When the pop-up is hidden, Filament still fills the schema with its defaults before it runs the action (`InteractsWithActions::mountAction()` mounts and fills, then calls the action straight away), so `$data['orders']` holds the lone order.

Add these private helpers next to `freeLines()`:

```php
    /**
     * The customer's placed orders on the order's drop, this one first.
     *
     * @return Collection<int, Order>
     */
    private static function customerOrders(Order $order): Collection
    {
        return CustomerOrders::onDrop($order->drop_id)->of($order);
    }

    /**
     * The customer's orders still to hand over: paid ones, and cash ones put aside for them.
     *
     * @return Collection<int, Order>
     */
    private static function toHandOver(Order $order): Collection
    {
        return self::customerOrders($order)
            ->filter(fn (Order $other): bool => in_array($other->status, OrderStatus::toFulfil(), true))
            ->values();
    }

    /**
     * One of the customer's orders as the hand-over list shows it: "FL-7K2QXM", "FL-7K2QXM · collect $200 cash",
     * "FL-7K2QXM · already handed over Sat 14 Nov" or "FL-7K2QXM · payment still clearing".
     */
    private static function handOverLabel(Order $order): string
    {
        $note = match ($order->status) {
            OrderStatus::CashOwed => 'collect '.Money::format($order->total).' cash',
            OrderStatus::Fulfilled => trim('already handed over '.($order->fulfilledDay() ?? '')),
            OrderStatus::Processing => 'payment still clearing',
            default => null,
        };

        return $note === null ? $order->reference() : "{$order->reference()} · {$note}";
    }

    /**
     * "Marked fulfilled", or "2 orders marked fulfilled". When some had changed since the pop-up opened: how many went,
     * and which didn't.
     *
     * @param  list<Order>  $notHandedOver
     */
    private static function notifyHandedOver(int $handedOver, array $notHandedOver): void
    {
        if ($notHandedOver === []) {
            Notification::make()
                ->title($handedOver === 1 ? 'Marked fulfilled' : Number::format($handedOver).' orders marked fulfilled')
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(Number::format($handedOver).' of '.Number::format($handedOver + count($notHandedOver)).' marked fulfilled')
            ->body(Arr::join(array_map(fn (Order $order): string => $order->reference(), $notHandedOver), ', ', ' and ').' had changed.')
            ->warning()
            ->send();
    }
```

**Step 5: Keep list rows to their own order**

In `app/Filament/Resources/Orders/Tables/OrdersTable.php`, replace `OrderActions::markFulfilled(),` in `recordActions()` with:

```php
                // A row offers it for its own order. The order's page also offers it on one already collected while
                // another of the customer's is waiting.
                OrderActions::markFulfilled()->visible(fn (Order $record): bool => in_array($record->status, OrderStatus::toFulfil(), true)),
```

That keeps the list from grouping every row's customer just to decide whether to show a button.

**Step 6: Run the tests to see them pass**

Run: `php artisan test --compact tests/Feature/Admin/HandOverAdminTest.php tests/Feature/Admin/OrderResourceTest.php`
Expected: PASS.

If `says which order had changed…` reports `Marked fulfilled` instead, Filament dropped the now-disabled option from the submitted state. Check `CheckboxList`'s dehydration in `vendor/filament/forms/src/Components/CheckboxList.php` and keep the race reported rather than removing the test.

**Step 7: Format, analyse and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/Filament/Resources/Orders app/Models/Order.php --memory-limit=2G --no-progress
git add app/Models/Order.php app/Filament/Resources/Orders/Actions/OrderActions.php app/Filament/Resources/Orders/Tables/OrdersTable.php tests/Feature/Admin/HandOverAdminTest.php
git commit -m "Hand over everything a customer has on the drop from Mark fulfilled, asking first when there's more than one order or cash to collect"
```

---

### Task 5: The order page, items first

The heading is the reference, with "Paid · Mikele Linke · Farm pickup" under it. Items come first: just "Items" for a lone order, or "Everything for Mikele · 2 orders" with a line per order. Order, Customer and Delivery fold away like Stripe. Item lines lose their prices, and the delivery fee isn't listed.

**Files:**
- Modify: `app/Filament/Resources/Orders/Pages/ViewOrder.php`
- Modify: `app/Filament/Resources/Orders/Schemas/OrderInfolist.php`
- Create: `resources/views/filament/orders/customer-orders.blade.php`
- Modify: `resources/css/filament/admin/theme.css`
- Modify: `tests/Feature/Admin/OrderResourceTest.php` (`shows everything about an order`)
- Test: `tests/Feature/Admin/HandOverAdminTest.php`

**Step 1: Write the tests**

In `tests/Feature/Admin/OrderResourceTest.php`, the test `shows everything about an order` asserts the line prices `'$24'` and `'$15'`, which are going. Change its assertion to:

```php
    livewire(ViewOrder::class, ['record' => $order->id])
        ->assertSee(['Sam Buyer', 'buyer@example.test', '1 Main St', 'Ballarat', $order->reference(), '$175'])
        ->assertSeeText('Items')
        ->assertDontSeeText('Everything for');
```

`$175` is the factory's order total, still shown in the Order section.

Add to the imports of `tests/Feature/Admin/HandOverAdminTest.php`:

```php
use App\Models\OrderItem;
use App\Models\Product;
```

Append:

```php
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
```

**Step 2: Run them to see them fail**

Run: `php artisan test --compact tests/Feature/Admin/HandOverAdminTest.php tests/Feature/Admin/OrderResourceTest.php`
Expected: the new `lists everything the customer has…` test FAILS: there's no subheading and no "Everything for…" heading yet. The updated `shows everything about an order` already passes. Its new assertions guard the lone-order branch, so they aren't expected to fail first.

**Step 3: Title and subheading**

Replace `app/Filament/Resources/Orders/Pages/ViewOrder.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\Actions\OrderActions;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Resources\Pages\ViewRecord;
use LogicException;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    /**
     * Just the order's reference: everything else about it is below.
     */
    public function getTitle(): string
    {
        return $this->order()->reference();
    }

    /**
     * What's needed at a glance on the farm: where the order's at, whose it is, and how it goes out.
     */
    public function getSubheading(): string
    {
        $order = $this->order();

        return implode(' · ', array_filter([$order->status->getLabel(), $order->customer_name, $order->deliverySummary()]));
    }

    protected function getHeaderActions(): array
    {
        return [
            OrderActions::markFulfilled(),
            OrderActions::giveFreeItem(),
            OrderActions::takeBackFreeItem(),
            OrderActions::markCashReceived(),
            OrderActions::cancelCashOrder(),
        ];
    }

    private function order(): Order
    {
        $record = $this->getRecord();

        return $record instanceof Order ? $record : throw new LogicException('The order page was opened without an order.');
    }
}
```

**Step 4: Items first, details folded away**

In `app/Filament/Resources/Orders/Schemas/OrderInfolist.php`:

- Imports: remove `RepeatableEntry`, and add `use App\Enums\OrderStatus;`, `use App\Enums\ProductType;`, `use App\Support\CustomerOrders;`, `use Filament\Infolists\Components\ViewEntry;` and `use Illuminate\Database\Eloquent\Collection;`.
- Add a class docblock:

```php
/**
 * The order's page, laid out for handing over on a phone: what to pack comes first, for every order the customer has
 * on the drop, and the details are folded away below.
 */
```

- Delete the `Section::make('Items')` block, and put this first in `->components([...])`:

```php
                Section::make(fn (Order $record): string => self::itemsHeading($record, CustomerOrders::onDrop($record->drop_id)->of($record)))
                    ->columnSpanFull()
                    ->schema([
                        ViewEntry::make('customer_orders')
                            ->hiddenLabel()
                            ->view('filament.orders.customer-orders')
                            ->state(fn (Order $record): array => self::customerOrders($record)),
                    ]),
```

- Add `->collapsed()` to `Section::make('Order')`, `Section::make('Customer')` and `Section::make('Delivery')`, straight after `make(...)`. Their entries are unchanged. Feedback and Stripe are unchanged.
- Add these methods to the class:

```php
    /**
     * "Items" for an order on its own, or "Everything for Mikele · 2 orders" when the customer has more on the drop.
     *
     * @param  Collection<int, Order>  $orders
     */
    private static function itemsHeading(Order $record, Collection $orders): string
    {
        return $orders->count() === 1
            ? 'Items'
            : 'Everything for '.($record->firstName() ?? 'this customer').' · '.$orders->count().' orders';
    }

    /**
     * The customer's orders on the drop, this one first, each with what to pack for it. The delivery fee isn't
     * packed, so it's left out.
     *
     * @return array<int, array{reference: string, status: OrderStatus, note: string|null, opened: bool, items: array<int, array{line: string, free: bool}>}>
     */
    private static function customerOrders(Order $record): array
    {
        return CustomerOrders::onDrop($record->drop_id, ['items.product'])->of($record)
            ->map(fn (Order $order): array => [
                'reference' => $order->reference(),
                'status' => $order->status,
                'note' => self::orderNote($order, $record),
                'opened' => $order->is($record),
                'items' => $order->items
                    ->reject(fn (OrderItem $item): bool => $item->product->type === ProductType::Delivery)
                    ->map(fn (OrderItem $item): array => ['line' => "{$item->quantity} × {$item->product->name}", 'free' => $item->free])
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * What else to know about one of the customer's orders, beside its status: the cash to collect, the day it was
     * handed over, and how it goes out when that's not how the opened order does.
     */
    private static function orderNote(Order $order, Order $opened): ?string
    {
        $note = array_filter([
            $order->status === OrderStatus::CashOwed ? 'Collect '.Money::format($order->total).' cash' : null,
            $order->status === OrderStatus::Fulfilled ? $order->fulfilledDay() : null,
            $order->deliverySummary() !== $opened->deliverySummary() ? $order->deliverySummary() : null,
        ]);

        return $note === [] ? null : implode(' · ', $note);
    }
```

For an order that wasn't placed (still at checkout), `CustomerOrders::of()` returns the page's own record, which `OrderResource::getRecordRouteBindingEloquentQuery()` already loads with `items.product`.

**Step 5: The view**

Create `resources/views/filament/orders/customer-orders.blade.php`:

```blade
{{--
    What to pack, from App\Filament\Resources\Orders\Schemas\OrderInfolist. When the customer has more than one order
    on the drop, each is listed with its status, the opened one first, so nothing they ordered is left behind.
--}}
@php
    /** @var array<int, array{reference: string, status: \App\Enums\OrderStatus, note: string|null, opened: bool, items: array<int, array{line: string, free: bool}>}> $orders */
    $orders = $getState();
@endphp

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    <div class="fl-customer-orders">
        @foreach ($orders as $order)
            <div class="fl-customer-order">
                @if (count($orders) > 1)
                    <p class="fl-customer-order-heading">
                        <span>{{ $order['reference'] }}</span>
                        <x-filament::badge :color="$order['status']->getColor()" size="sm">{{ $order['status']->getLabel() }}</x-filament::badge>
                        @if ($order['opened'])
                            <span class="fl-customer-order-note">This one</span>
                        @endif
                        @if ($order['note'] !== null)
                            <span class="fl-customer-order-note">{{ $order['note'] }}</span>
                        @endif
                    </p>
                @endif
                <ul class="fl-customer-order-items">
                    @foreach ($order['items'] as $item)
                        <li>
                            <span>{{ $item['line'] }}</span>
                            @if ($item['free'])
                                <x-filament::badge color="success" size="sm">Free</x-filament::badge>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</x-dynamic-component>
```

Free items still read "Free" (capital F), which `tests/Feature/Admin/FreeItemsAdminTest.php` looks for.

**Step 6: The styles**

Append to `resources/css/filament/admin/theme.css`:

```css
/* A customer's orders on the order page, from resources/views/filament/orders/customer-orders.blade.php. */
.fl-customer-order + .fl-customer-order {
    @apply mt-4 border-t border-gray-200 pt-4 dark:border-white/10;
}

.fl-customer-order-heading {
    @apply mb-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-semibold text-gray-950 dark:text-white;
}

.fl-customer-order-note {
    @apply font-normal text-gray-500 dark:text-gray-400;
}

.fl-customer-order-items {
    @apply space-y-1.5 text-sm text-gray-950 dark:text-white;
}

.fl-customer-order-items li {
    @apply flex flex-wrap items-center gap-2;
}
```

Run: `npm run build`
Expected: builds without errors.

**Step 7: Run the tests to see them pass**

Run: `php artisan test --compact tests/Feature/Admin`
Expected: PASS, including `FreeItemsAdminTest` and `OrderFeedbackAdminTest`. Collapsed sections still render their content, so those tests' `assertSee` checks find it.

**Step 8: Format, analyse and commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/Filament/Resources/Orders --memory-limit=2G --no-progress
git add app/Filament/Resources/Orders/Pages/ViewOrder.php app/Filament/Resources/Orders/Schemas/OrderInfolist.php resources/views/filament/orders/customer-orders.blade.php resources/css/filament/admin/theme.css tests/Feature/Admin/OrderResourceTest.php tests/Feature/Admin/HandOverAdminTest.php
git commit -m "Put what to pack first on the order page, across all the customer's orders, and fold the details away"
```

---

### Task 6: Check everything

**Step 1: Static checks**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector process --dry-run --no-progress-bar
composer analyse
```

Expected: Rector suggests nothing and PHPStan reports no errors. If Rector suggests a change, apply it with `vendor/bin/rector process --no-progress-bar`, then re-run Pint and the affected tests.

**Step 2: The affected tests**

```bash
php artisan test --compact tests/Feature/Support/CustomerOrdersTest.php tests/Feature/Orders/HandOverOrdersTest.php tests/Feature/Admin tests/Feature/Mcp/ReadToolsTest.php
```

Expected: PASS.

**Step 3: The real browser**

`tests/Browser/AdminTest.php` renders the order pages in Chrome and fails on JavaScript errors. It needs the built assets from Task 5.

Run: `php artisan test --compact tests/Browser/AdminTest.php`
Expected: PASS.

**Step 4: Look at it on a phone**

Use the `run` skill (or the Chrome tools) to open the admin at phone width (390px wide). Check that:
- the orders list shows Order, Status and Customer only, with "2 orders" and a delivery's night and suburb under the name;
- To pick up and To deliver have counts;
- an order of a customer with two orders shows "Everything for … · 2 orders" first, with Order, Customer and Delivery folded away;
- Mark fulfilled opens the pop-up with both orders ticked;
- dark mode still reads well.

Take screenshots to show the owner.

**Step 5: Hand back**

Ask the owner to run the whole suite with `php artisan test --compact`. Don't push to `main` until they say so: a push deploys to the live site.
