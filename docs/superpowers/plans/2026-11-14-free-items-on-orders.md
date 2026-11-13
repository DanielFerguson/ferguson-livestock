# Free Items on Orders Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Let the owner give a customer free items (sample packs) on an existing order, taken from the drop's stock, from the admin order page and from Claude.

**Architecture:** A free item is an `order_items` row with `free = true` and `unit_price = 0`, held through `StockLedger` with the same locked, all-or-nothing stock take as checkout. Two actions (`GiveFreeItems`, `TakeBackFreeItem`) hold the rules; Filament header actions and two MCP tools call them. `DropSales` reports free units as `given_away`, not `sold`.

**Tech Stack:** Laravel 13, PHP 8.5, Postgres, Filament 5.9, laravel/mcp 1.x, Pest 5.

**Spec:** `docs/superpowers/specs/2026-11-14-free-items-on-orders-design.md`

**Conventions to follow:** read `AGENTS.md`. PHPDoc over inline comments, curly braces always, explicit types. Owner-facing messages use curly apostrophes (’). Run tests with `php artisan test --compact <file>`. If tests fail with phantom errors, another run may be sharing the test database: see memory note "Shared test database collisions" and use a private `DB_DATABASE`.

---

### Task 1: Give free items (schema, ledger, action)

**Files:**
- Create: `database/migrations/<timestamp>_add_free_to_order_items_table.php` (via artisan)
- Create: `app/Exceptions/CannotChangeOrder.php`
- Create: `app/Actions/Orders/GiveFreeItems.php`
- Modify: `app/Models/OrderItem.php`
- Modify: `database/factories/OrderItemFactory.php`
- Modify: `app/Exceptions/InsufficientStock.php` (gains `describe()`, moved from `RecordCashOrder::shortage()`)
- Modify: `app/Actions/Orders/RecordCashOrder.php` (use `describe()`, delete `shortage()`)
- Modify: `app/Stock/StockLedger.php`
- Test: `tests/Feature/Checkout/FreeItemsTest.php`

**Step 1: Create the test file**

Run: `php artisan make:test --pest Checkout/FreeItemsTest --no-interaction`

Replace its contents with:

```php
<?php

declare(strict_types=1);

use App\Actions\Orders\GiveFreeItems;
use App\Checkout\OrderPayments;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Exceptions\CannotChangeOrder;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Order;
use App\Stock\StockLedger;
use Tests\Fixtures\StockedDrop;
use Tests\Fixtures\StockInvariant;

/**
 * Sam's pickup of one 5kg box, its stock held as checkout holds it.
 */
function samsPickup(StockedDrop $shop, OrderStatus $status = OrderStatus::Paid): Order
{
    return app(StockLedger::class)->setAside($shop->drop, [$shop->box->id => 1], [
        'status' => $status,
        'delivery_method' => DeliveryMethod::Pickup,
        'customer_name' => 'Sam Buyer',
    ])->refresh();
}

it('gives free items from the drop’s stock without changing what the order cost', function (OrderStatus $status): void {
    $shop = StockedDrop::create(boxes: 5, mince: 10);

    $order = app(GiveFreeItems::class)(samsPickup($shop, $status), [$shop->mince->id => 2]);

    expect($order->total)->toBe(16000);
    expect($order->items->firstWhere('drop_item_id', $shop->mince->id))
        ->free->toBeTrue()
        ->unit_price->toBe(0)
        ->quantity->toBe(2);
    expect($shop->mince->refresh()->available)->toBe(8);
    StockInvariant::check($shop->drop);
})->with([OrderStatus::Paid, OrderStatus::Fulfilled, OrderStatus::CashOwed]);

it('takes a free bundle from the stock it’s packed from', function (): void {
    $shop = StockedDrop::create(boxes: 5);

    app(GiveFreeItems::class)(samsPickup($shop), [$shop->largeBox->id => 1]);

    expect($shop->box->refresh()->available)->toBe(2);
    StockInvariant::check($shop->drop);
});

it('gives free items after the drop has closed, since pickups come after', function (): void {
    $shop = StockedDrop::create(mince: 10);
    $order = samsPickup($shop);
    $shop->drop->update(['opens_at' => now()->subWeek(), 'closes_at' => now()->subDay()]);

    app(GiveFreeItems::class)($order, [$shop->mince->id => 1]);

    expect($shop->mince->refresh()->available)->toBe(9);
});

it('says what’s left when there isn’t enough, giving nothing', function (): void {
    $shop = StockedDrop::create(mince: 1);
    $order = samsPickup($shop);

    expect(fn (): Order => app(GiveFreeItems::class)($order, [$shop->mince->id => 2]))
        ->toThrow(CannotChangeOrder::class, 'Only 1 × 500g Beef Mince left. If you’ve got more on hand, raise its stock on the drop first.');

    expect($order->items()->count())->toBe(1)
        ->and($shop->mince->refresh()->available)->toBe(1);
});

it('refuses an order whose stock isn’t held for good', function (Closure $makeOrder): void {
    $shop = StockedDrop::create(mince: 10);
    $order = $makeOrder($shop);

    expect(fn (): Order => app(GiveFreeItems::class)($order, [$shop->mince->id => 1]))
        ->toThrow(CannotChangeOrder::class, 'only paid, fulfilled and cash owed orders can take free items');

    expect($shop->mince->refresh()->available)->toBe(10);
})->with([
    'at checkout' => [fn (StockedDrop $shop): Order => samsPickup($shop, OrderStatus::Pending)],
    'abandoned' => [function (StockedDrop $shop): Order {
        $order = samsPickup($shop, OrderStatus::Pending);
        app(OrderPayments::class)->markExpired($order);

        return $order->refresh();
    }],
]);

it('refuses something the order’s drop doesn’t sell', function (Closure $quantities, string $message): void {
    $shop = StockedDrop::create();
    $order = samsPickup($shop);

    expect(fn (): Order => app(GiveFreeItems::class)($order, $quantities($shop)))->toThrow(CannotChangeOrder::class, $message);

    expect($order->items()->count())->toBe(1);
})->with([
    'nothing' => [fn (StockedDrop $shop): array => [$shop->mince->id => 0], 'Choose at least one item to give.'],
    'the delivery fee' => [fn (StockedDrop $shop): array => [$shop->delivery->id => 1], 'isn’t sold in'],
    'another drop’s item' => [fn (StockedDrop $shop): array => [DropItem::factory()->for(Drop::factory()->draft())->create()->id => 1], 'isn’t sold in'],
]);
```

**Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Checkout/FreeItemsTest.php`
Expected: FAIL, class `App\Actions\Orders\GiveFreeItems` not found.

**Step 3: Migration**

Run: `php artisan make:migration add_free_to_order_items_table --table=order_items --no-interaction`

Contents:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            // Given away rather than sold, like a sample pack handed over at pickup. It still takes stock.
            $table->boolean('free')->default(false)->after('unit_price');
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_free_costs_nothing CHECK (NOT free OR unit_price = 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT order_items_free_costs_nothing');

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('free');
        });
    }
};
```

**Step 4: Model and factory**

`app/Models/OrderItem.php`:
- Class doc: `One line of an order, at the price when it was placed, or free when it was given away.`
- Add `@property bool $free` after `@property int $unit_price`.
- Add `'free'` to `#[Fillable]` after `'unit_price'`.
- Add, above `order()`:

```php
    /**
     * Mirrors the column's default, so a line checkout has just created knows it's sold, not given away.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'free' => false,
    ];
```

- Add `'free' => 'boolean',` to `casts()`.

`database/factories/OrderItemFactory.php`, add after `definition()`:

```php
    /**
     * Given away rather than sold, like a sample pack at pickup.
     */
    public function free(): static
    {
        return $this->state(fn (): array => ['free' => true, 'unit_price' => 0]);
    }
```

**Step 5: Exception, and the shortage message moved into `InsufficientStock`**

`app/Exceptions/CannotChangeOrder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A change to an order that the rules don't allow, like a free item on an abandoned checkout, with the reason in
 * words the owner can act on.
 */
class CannotChangeOrder extends RuntimeException {}
```

`app/Exceptions/InsufficientStock.php`, add (with `use App\Models\Drop;` and `use App\Models\DropItem;`):

```php
    /**
     * "Only 3 × 5kg Beef Box left. 500g Beef Mince is sold out." The drop's items must be loaded with their products.
     */
    public function describe(Drop $drop): string
    {
        $parts = [];

        foreach ($this->available as $dropItemId => $left) {
            $name = $drop->items->first(fn (DropItem $item): bool => $item->id === $dropItemId)?->product->name ?? 'One of the items';

            $parts[] = $left > 0 ? "Only {$left} × {$name} left." : "{$name} is sold out.";
        }

        return $parts === [] ? 'There isn’t enough stock left for this order.' : implode(' ', $parts);
    }
```

`app/Actions/Orders/RecordCashOrder.php`: replace `$this->shortage($drop, $exception)` with `$exception->describe($drop)` and delete the private `shortage()` method.

**Step 6: `StockLedger::addFree()`**

In `app/Stock/StockLedger.php`, pull the line creation out of `hold()` into a helper, so both use it. Replace the `foreach ($lines as $line) { $order->items()->create([...]); }` block in `hold()` with `$this->addLines($order, $lines);`, and add:

```php
    /**
     * Hold stock for items given away on an order, like a sample pack handed over at pickup. They're added as free
     * lines, so the order's total doesn't change. The order is locked first, so it can't be cancelled, releasing its
     * stock, while they're added.
     *
     * @param  array<int, int>  $quantities  drop item ID => quantity
     * @param  Closure(Order): void  $mayAdd  throws when the freshly locked order can't take them
     *
     * @param-immediately-invoked-callable $mayAdd
     *
     * @throws InsufficientStock
     * @throws CheckoutBusy
     */
    public function addFree(Order $order, array $quantities, Closure $mayAdd): void
    {
        $this->waitingForLocks(fn () => DB::transaction(function () use ($order, $quantities, $mayAdd): void {
            DB::statement("SET LOCAL lock_timeout = '".self::LOCK_TIMEOUT."'");

            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $mayAdd($order);

            // Stock taken for an order that's already given its stock back would never be given back.
            if ($order->released_at !== null) {
                throw new InvalidArgumentException("Order {$order->id}’s stock has been released.");
            }

            $drop = Drop::query()->with('items.product.bundle')->findOrFail($order->drop_id);
            $lines = $this->lines($drop, $quantities);
            $this->takeStock($lines);
            $this->addLines($order, $lines, free: true);
        }, attempts: 3));

        DropSnapshot::forget();
    }

    /**
     * @param  list<StockLine>  $lines
     */
    private function addLines(Order $order, array $lines, bool $free = false): void
    {
        foreach ($lines as $line) {
            $order->items()->create([
                'drop_item_id' => $line->item->id,
                'product_id' => $line->item->product_id,
                'quantity' => $line->quantity,
                'unit_price' => $free ? 0 : $line->item->price,
                'free' => $free,
                'stock_drop_item_id' => $line->stock?->id,
                'stock_units' => $line->unitsEach,
            ]);
        }
    }
```

Lock order: the order row, then drop items in ID order. `cancelCashOrder` and late-payment `reclaim()` take them in the same order, so there's no deadlock.

**Step 7: `GiveFreeItems`**

`app/Actions/Orders/GiveFreeItems.php` (`php artisan make:class Actions/Orders/GiveFreeItems --no-interaction`, then replace):

```php
<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Exceptions\CannotChangeOrder;
use App\Exceptions\CheckoutBusy;
use App\Exceptions\InsufficientStock;
use App\Models\DropItem;
use App\Models\Order;
use App\Stock\StockLedger;

/**
 * Gives a customer something extra on their order, like a sample pack at pickup. It comes out of the drop's stock
 * like anything sold, so the stock stays right, but it's free: what they paid doesn't change. The drop may have
 * closed, since pickups come after. Nothing is sent.
 */
final readonly class GiveFreeItems
{
    /** Orders whose stock is held for good: paid for, or put aside for cash. */
    private const array CAN_TAKE_FREE_ITEMS = [OrderStatus::Paid, OrderStatus::Fulfilled, OrderStatus::CashOwed];

    public function __construct(private StockLedger $stock) {}

    public static function canTakeFreeItems(Order $order): bool
    {
        return in_array($order->status, self::CAN_TAKE_FREE_ITEMS, true) && $order->released_at === null;
    }

    /**
     * @param  array<int, int>  $quantities  drop item ID => quantity
     *
     * @throws CannotChangeOrder
     */
    public function __invoke(Order $order, array $quantities): Order
    {
        $drop = $order->drop()->with('items.product')->firstOrFail();
        $quantities = array_filter($quantities, fn (int $quantity): bool => $quantity > 0);

        if ($quantities === []) {
            throw new CannotChangeOrder('Choose at least one item to give.');
        }

        foreach (array_keys($quantities) as $dropItemId) {
            $item = $drop->items->first(fn (DropItem $item): bool => $item->id === $dropItemId);

            if (! $item instanceof DropItem || $item->product->type === ProductType::Delivery) {
                throw new CannotChangeOrder("Something you chose isn’t sold in {$drop->name}.");
            }
        }

        try {
            $this->stock->addFree($order, $quantities, function (Order $order): void {
                if (! self::canTakeFreeItems($order)) {
                    throw new CannotChangeOrder("Order {$order->reference()} is {$order->status->getLabel()}; only paid, fulfilled and cash owed orders can take free items.");
                }
            });
        } catch (InsufficientStock $exception) {
            throw new CannotChangeOrder($exception->describe($drop).' If you’ve got more on hand, raise its stock on the drop first.', $exception->getCode(), previous: $exception);
        } catch (CheckoutBusy $exception) {
            throw new CannotChangeOrder('Lots of orders are coming in right now. Try again in a moment.', $exception->getCode(), previous: $exception);
        }

        return $order->refresh()->load(['items.product', 'drop']);
    }
}
```

**Step 8: Migrate and run the tests**

Run: `php artisan migrate --no-interaction && php artisan test --compact tests/Feature/Checkout/FreeItemsTest.php tests/Feature/Checkout/RecordCashOrderTest.php tests/Feature/Checkout/StockLedgerTest.php`
Expected: all PASS (RecordCashOrderTest proves the moved shortage message still reads the same).

**Step 9: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A app database tests
git commit -m "Give customers free items on their order, from the drop's stock"
```

---

### Task 2: Take a free item back

**Files:**
- Create: `app/Actions/Orders/TakeBackFreeItem.php`
- Modify: `app/Stock/StockLedger.php`
- Test: `tests/Feature/Checkout/FreeItemsTest.php`

**Step 1: Add failing tests** to `FreeItemsTest.php` (add `use App\Actions\Orders\RecordCashOrder;` and `use App\Actions\Orders\TakeBackFreeItem;`):

```php
it('takes a free item back off, putting its stock back on sale', function (): void {
    $shop = StockedDrop::create(mince: 10);
    $order = app(GiveFreeItems::class)(samsPickup($shop), [$shop->mince->id => 2]);

    app(TakeBackFreeItem::class)($order->items->firstWhere('free', true));

    expect($order->items()->pluck('drop_item_id')->all())->toBe([$shop->box->id])
        ->and($shop->mince->refresh()->available)->toBe(10);
    StockInvariant::check($shop->drop);
});

it('only takes back what was given free, and only once', function (): void {
    $shop = StockedDrop::create(mince: 10);
    $order = app(GiveFreeItems::class)(samsPickup($shop), [$shop->mince->id => 2]);
    $free = $order->items->firstWhere('free', true);
    app(TakeBackFreeItem::class)($free);

    expect(fn () => app(TakeBackFreeItem::class)($order->items->firstWhere('free', false)))
        ->toThrow(CannotChangeOrder::class, '5kg Beef Box was paid for, so it stays on the order.')
        ->and(fn () => app(TakeBackFreeItem::class)($free))
        ->toThrow(CannotChangeOrder::class, 'has already come off the order');

    expect($shop->mince->refresh()->available)->toBe(10)
        ->and($shop->box->refresh()->available)->toBe(4);
});

it('puts free items back on sale with the rest when a cash order is cancelled', function (): void {
    $shop = StockedDrop::create(boxes: 5, mince: 10);
    $owed = app(RecordCashOrder::class)(drop: $shop->drop, quantities: [$shop->box->id => 1], deliveryMethod: DeliveryMethod::Pickup, deliveryDay: null, customerName: 'Sam', phone: null, address: null, paid: false);
    app(GiveFreeItems::class)($owed, [$shop->mince->id => 2]);

    app(OrderPayments::class)->cancelCashOrder($owed);

    expect($shop->box->refresh()->available)->toBe(5)
        ->and($shop->mince->refresh()->available)->toBe(10);
    StockInvariant::check($shop->drop);
});
```

**Step 2: Run to see the first two fail**

Run: `php artisan test --compact tests/Feature/Checkout/FreeItemsTest.php`
Expected: FAIL, `TakeBackFreeItem` not found. The cancel test should already pass, since `release()` sums every line. It's there to guard that.

**Step 3: `StockLedger::takeBackFree()`**

```php
    /**
     * Take a free line off its order and put its stock back on sale, for an item given by mistake. Returns false,
     * changing nothing, when the line isn't free or is already gone, or its order's stock went back on sale already.
     */
    public function takeBackFree(OrderItem $line): bool
    {
        $takenBack = DB::transaction(function () use ($line): bool {
            $order = Order::query()->lockForUpdate()->find($line->order_id);
            $free = OrderItem::query()->whereKey($line->id)->where('free', true)->first();

            if (! $order instanceof Order || $order->released_at !== null || ! $free instanceof OrderItem) {
                return false;
            }

            if ($free->stock_drop_item_id !== null) {
                DropItem::query()->whereKey($free->stock_drop_item_id)->increment('available', $free->quantity * $free->stock_units);
            }

            $free->delete();

            return true;
        });

        if ($takenBack) {
            DropSnapshot::forget();
        }

        return $takenBack;
    }
```

**Step 4: `TakeBackFreeItem`**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Exceptions\CannotChangeOrder;
use App\Models\OrderItem;
use App\Stock\StockLedger;

/**
 * Takes a free item back off an order, for one given by mistake, and puts its stock back on sale. Only free items:
 * what a customer paid for stays on their order.
 */
final readonly class TakeBackFreeItem
{
    public function __construct(private StockLedger $stock) {}

    /**
     * @throws CannotChangeOrder
     */
    public function __invoke(OrderItem $line): void
    {
        if (! $line->free) {
            throw new CannotChangeOrder("{$line->product->name} was paid for, so it stays on the order.");
        }

        if (! $this->stock->takeBackFree($line)) {
            throw new CannotChangeOrder("That {$line->product->name} has already come off the order, or the order was cancelled.");
        }
    }
}
```

**Step 5: Run tests**

Run: `php artisan test --compact tests/Feature/Checkout/FreeItemsTest.php`
Expected: PASS.

**Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A app tests
git commit -m "Take a free item back off an order, putting its stock back on sale"
```

---

### Task 3: Report free items as given away

**Files:**
- Modify: `app/Stock/DropSales.php` (split `sold` / `given_away`; gains `left()`, moved from `CashOrderForm`)
- Modify: `app/Filament/Resources/Orders/Schemas/CashOrderForm.php` (use `DropSales::left()`, delete its private `left()`)
- Modify: `app/Filament/Widgets/CurrentDropStats.php`
- Modify: `app/Support/DeliveryRun.php` (`items()` marks free lines)
- Modify: `app/Mcp/Support/Presenter.php` (detailed lines gain `product_id` and `free`)
- Test: `tests/Feature/Admin/CurrentDropStatsTest.php`

**Step 1: Failing test** in `CurrentDropStatsTest.php` (add `use App\Actions\Orders\GiveFreeItems;` and `use App\Enums\OrderStatus;`):

```php
it('counts free items as given away, not sold', function (): void {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));
    $shop = StockedDrop::create(boxes: 5, mince: 10);
    $order = app(StockLedger::class)->setAside($shop->drop, [$shop->mince->id => 1], ['status' => OrderStatus::Paid, 'delivery_method' => DeliveryMethod::Pickup]);
    app(GiveFreeItems::class)($order, [$shop->mince->id => 2]);

    livewire(CurrentDropStats::class)
        ->assertTableColumnStateSet('sold', 1, $shop->mince)
        ->assertTableColumnStateSet('given_away', 2, $shop->mince)
        ->assertTableColumnStateSet('left', 7, $shop->mince)
        ->assertTableColumnStateSet('revenue', '$12', $shop->mince);
});
```

**Step 2: Run to see it fail**

Run: `php artisan test --compact tests/Feature/Admin/CurrentDropStatsTest.php`
Expected: FAIL, no `given_away` column.

**Step 3: `DropSales`**

Replace `forItem()`:

```php
    /**
     * @return array{sold: int, given_away: int, at_checkout: int, left: int|null, revenue: int}
     */
    public static function forItem(DropItem $item): array
    {
        // A cash order put aside for a friend is sold, though the cash may not be in hand yet. A free item, like a
        // sample pack at pickup, takes stock but isn't a sale.
        [$givenAway, $sold] = self::lines($item, [OrderStatus::Paid, OrderStatus::Fulfilled, OrderStatus::CashOwed])
            ->partition(fn (OrderItem $line): bool => $line->free);

        return [
            'sold' => $sold->sum(fn (OrderItem $line): int => $line->quantity),
            'given_away' => $givenAway->sum(fn (OrderItem $line): int => $line->quantity),
            'at_checkout' => self::quantity($item, [OrderStatus::Pending, OrderStatus::Processing]),
            'left' => $item->hasOwnStock() ? $item->available : null,
            'revenue' => $sold->sum(fn (OrderItem $line): int => $line->lineTotal()),
        ];
    }
```

Move `left()` from `CashOrderForm` here as a public static method, unchanged apart from visibility (add `use App\Models\Drop;`):

```php
    /**
     * How many more of an item can be had: its own stock, or for a bundle, how many its component's stock makes.
     * The drop's items must be loaded with their products and bundles.
     */
    public static function left(Drop $drop, DropItem $item): int
    {
        if ($item->hasOwnStock()) {
            return (int) $item->available;
        }

        $bundle = $item->product->bundle;
        $component = $bundle === null ? null : $drop->items->firstWhere('product_id', $bundle->component_product_id);

        return $component instanceof DropItem && $bundle !== null ? intdiv((int) $component->available, max(1, $bundle->units)) : 0;
    }
```

In `CashOrderForm::itemOptions()`, change `self::left($drop, $item)` to `DropSales::left($drop, $item)`, add `use App\Stock\DropSales;`, and delete its private `left()`.

**Step 4: Widget column**, after the `sold` column in `CurrentDropStats`:

```php
                TextColumn::make('given_away')->label('Given away')->state(fn (DropItem $record): int => DropSales::forItem($record)['given_away']),
```

**Step 5: Run sheet and Claude's order view**

`DeliveryRun::items()`: free lines are still packed, so they stay, marked:

```php
    /**
     * "1 × 5kg Beef Box, 2 × 500g Beef Mince, 1 × Sausage Pack (6) (free)". The delivery fee isn't something to pack,
     * so it's left out.
     */
    public static function items(Order $order): string
    {
        return $order->items
            ->reject(fn (OrderItem $item): bool => $item->product->type === ProductType::Delivery)
            ->map(fn (OrderItem $item): string => "{$item->quantity} × {$item->product->name}".($item->free ? ' (free)' : ''))
            ->implode(', ');
    }
```

(`OrderConfirmationText` uses this too. That's fine: a text still waiting to be sent would list the free item as free.)

`Presenter::order()` detailed `lines`: add `'product_id' => $line->product_id,` and `'free' => $line->free,`. Claude needs the product id to remove a free item.

**Step 6: Run tests**

Run: `php artisan test --compact tests/Feature/Admin/CurrentDropStatsTest.php tests/Feature/Admin/OrderResourceTest.php tests/Feature/Mcp/ReadToolsTest.php tests/Feature/Sms/OrderConfirmationTextTest.php`
Expected: PASS.

**Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A app tests
git commit -m "Count free items as given away, not sold"
```

---

### Task 4: Admin order page

**Files:**
- Modify: `app/Filament/Resources/Orders/Actions/OrderActions.php`
- Modify: `app/Filament/Resources/Orders/Pages/ViewOrder.php`
- Modify: `app/Filament/Resources/Orders/Schemas/OrderInfolist.php`
- Test: `tests/Feature/Admin/OrderResourceTest.php`

**Step 1: Failing tests** at the end of `OrderResourceTest.php` (add `use App\Actions\Orders\GiveFreeItems;` and `use App\Stock\StockLedger;`):

```php
describe('free items', function (): void {
    beforeEach(function (): void {
        $this->shop = StockedDrop::create(mince: 10);
        $this->order = app(StockLedger::class)->setAside($this->shop->drop, [$this->shop->box->id => 1], ['status' => OrderStatus::Paid, 'delivery_method' => DeliveryMethod::Pickup, 'customer_name' => 'Sam Buyer']);
    });

    it('adds a free item from the order’s page, taking it from the drop’s stock', function (): void {
        livewire(ViewOrder::class, ['record' => $this->order->id])
            ->callAction('giveFreeItem', ['drop_item_id' => $this->shop->mince->id, 'quantity' => 2])
            ->assertHasNoActionErrors()
            ->assertNotified('Free item added')
            ->assertSee('Free');

        expect($this->order->refresh()->total)->toBe(16000)
            ->and($this->shop->mince->refresh()->available)->toBe(8);
    });

    it('says why a free item can’t be added, keeping it open to change', function (): void {
        livewire(ViewOrder::class, ['record' => $this->order->id])
            ->callAction('giveFreeItem', ['drop_item_id' => $this->shop->mince->id, 'quantity' => 11])
            ->assertNotified('Not added')
            ->assertActionMounted('giveFreeItem');

        expect($this->shop->mince->refresh()->available)->toBe(10);
    });

    it('removes a free item from the order’s page, putting it back on sale', function (): void {
        app(GiveFreeItems::class)($this->order, [$this->shop->mince->id => 2]);

        livewire(ViewOrder::class, ['record' => $this->order->id])
            ->callAction('takeBackFreeItem')
            ->assertNotified('Free item removed');

        expect($this->order->items()->where('free', true)->count())->toBe(0)
            ->and($this->shop->mince->refresh()->available)->toBe(10);
    });

    it('only offers free items while the order’s stock is held, and removing them once there are some', function (): void {
        $atCheckout = Order::factory()->pending()->for($this->shop->drop)->create();

        livewire(ViewOrder::class, ['record' => $atCheckout->id])->assertActionHidden('giveFreeItem');
        livewire(ViewOrder::class, ['record' => $this->order->id])
            ->assertActionVisible('giveFreeItem')
            ->assertActionHidden('takeBackFreeItem');
    });
});
```

**Step 2: Run to see them fail**

Run: `php artisan test --compact tests/Feature/Admin/OrderResourceTest.php --filter='free items'`
Expected: FAIL, action `giveFreeItem` not found.

**Step 3: Actions** in `OrderActions` (imports: `App\Actions\Orders\GiveFreeItems`, `App\Actions\Orders\TakeBackFreeItem`, `App\Enums\ProductType`, `App\Exceptions\CannotChangeOrder`, `App\Models\DropItem`, `App\Models\OrderItem`, `App\Stock\DropSales`, `Filament\Forms\Components\TextInput`):

```php
    public static function giveFreeItem(): Action
    {
        return Action::make('giveFreeItem')
            ->label('Add free item')
            ->icon(Heroicon::OutlinedGift)
            ->color('gray')
            ->visible(fn (Order $record): bool => GiveFreeItems::canTakeFreeItems($record))
            ->modalHeading(fn (Order $record): string => 'Give '.($record->firstName() ?? 'them').' something free')
            ->modalDescription('It comes out of the drop’s stock. What they paid doesn’t change, and they aren’t told.')
            ->schema([
                Select::make('drop_item_id')
                    ->label('Item')
                    ->options(fn (Order $record): array => self::freeItemOptions($record))
                    ->required(),
                TextInput::make('quantity')->integer()->minValue(1)->default(1)->required(),
            ])
            ->modalSubmitActionLabel('Add to order')
            ->action(function (array $data, Order $record, GiveFreeItems $giveFreeItems, Action $action): void {
                $dropItemId = is_numeric($data['drop_item_id'] ?? null) ? (int) $data['drop_item_id'] : 0;
                $quantity = is_numeric($data['quantity'] ?? null) ? (int) $data['quantity'] : 0;

                try {
                    $giveFreeItems($record, [$dropItemId => $quantity]);

                    Notification::make()->title('Free item added')->body('It’s come out of the drop’s stock.')->success()->send();
                } catch (CannotChangeOrder $exception) {
                    Notification::make()->title('Not added')->body($exception->getMessage())->danger()->send();

                    $action->halt();
                }
            });
    }

    public static function takeBackFreeItem(): Action
    {
        return Action::make('takeBackFreeItem')
            ->label('Remove free item')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Order $record): bool => $record->released_at === null && self::freeLines($record) !== [])
            ->modalHeading('Remove a free item?')
            ->modalDescription('It goes back on sale on the website.')
            ->schema([
                Select::make('order_item_id')
                    ->label('Item')
                    ->options(fn (Order $record): array => self::freeLines($record))
                    ->default(fn (Order $record): ?int => count(self::freeLines($record)) === 1 ? array_key_first(self::freeLines($record)) : null)
                    ->required(),
            ])
            ->modalSubmitActionLabel('Remove')
            ->action(function (array $data, Order $record, TakeBackFreeItem $takeBackFreeItem, Action $action): void {
                $line = $record->items()->whereKey(is_numeric($data['order_item_id'] ?? null) ? (int) $data['order_item_id'] : 0)->first();

                try {
                    if (! $line instanceof OrderItem) {
                        throw new CannotChangeOrder('That item isn’t on this order.');
                    }

                    $takeBackFreeItem($line);
                    $record->refresh();

                    Notification::make()->title('Free item removed')->body('It’s back on sale.')->success()->send();
                } catch (CannotChangeOrder $exception) {
                    Notification::make()->title('Not removed')->body($exception->getMessage())->danger()->send();

                    $action->halt();
                }
            });
    }

    /**
     * What the order's drop sells, with what's left: "Sausage Pack (6) · 12 left".
     *
     * @return array<int, string>
     */
    private static function freeItemOptions(Order $order): array
    {
        $drop = $order->drop()->with('items.product.bundle')->firstOrFail();

        return $drop->items
            ->reject(fn (DropItem $item): bool => $item->product->type === ProductType::Delivery)
            ->sortBy(fn (DropItem $item): int => $item->product->sort)
            ->mapWithKeys(fn (DropItem $item): array => [$item->id => "{$item->product->name} · ".DropSales::left($drop, $item).' left'])
            ->all();
    }

    /**
     * The order's free items: "2 × Sausage Pack (6)".
     *
     * @return array<int, string>
     */
    private static function freeLines(Order $order): array
    {
        return $order->items
            ->filter(fn (OrderItem $line): bool => $line->free)
            ->mapWithKeys(fn (OrderItem $line): array => [$line->id => "{$line->quantity} × {$line->product->name}"])
            ->all();
    }
```

`GiveFreeItems` refreshes the `Order` it's given, so the page's Items list updates in the same response. `TakeBackFreeItem` takes a line, so the action refreshes `$record` itself.

**Step 4: Page and infolist**

`ViewOrder::getHeaderActions()`: put `OrderActions::giveFreeItem(), OrderActions::takeBackFreeItem(),` after `OrderActions::markFulfilled(),`.

`OrderInfolist`, the Items `line_total` entry:

```php
TextEntry::make('line_total')->hiddenLabel()->state(fn (OrderItem $record): string => $record->free ? 'Free' : Money::format($record->lineTotal())),
```

**Step 5: Run tests**

Run: `php artisan test --compact tests/Feature/Admin/OrderResourceTest.php`
Expected: PASS. If `assertActionMounted` fails after `halt()`, check that `halt()` (not `cancel()`) was used. `halt()` keeps the modal open.

**Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A app tests
git commit -m "Add and remove free items from an order's admin page"
```

---

### Task 5: Claude tools

**Files:**
- Create: `app/Mcp/Tools/Orders/GiveFreeItemsTool.php`
- Create: `app/Mcp/Tools/Orders/RemoveFreeItemTool.php`
- Modify: `app/Mcp/Support/Changes.php` (catch `CannotChangeOrder`)
- Modify: `app/Mcp/Servers/FergusonLivestockServer.php` (register tools, add an instructions line)
- Test: `tests/Feature/Mcp/FreeItemsToolsTest.php`

**Step 1: Failing tests**

Run: `php artisan make:test --pest Mcp/FreeItemsToolsTest --no-interaction`, then:

```php
<?php

declare(strict_types=1);

use App\Actions\Orders\GiveFreeItems;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Mcp\Servers\FergusonLivestockServer;
use App\Mcp\Tools\Orders\GiveFreeItemsTool;
use App\Mcp\Tools\Orders\RemoveFreeItemTool;
use App\Models\Order;
use App\Models\User;
use App\Stock\StockLedger;
use Tests\Fixtures\StockedDrop;

beforeEach(function (): void {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->server = FergusonLivestockServer::actingAs(User::factory()->create(['email' => 'daniel@example.com']));
});

function paidPickup(StockedDrop $shop): Order
{
    return app(StockLedger::class)->setAside($shop->drop, [$shop->box->id => 1], ['status' => OrderStatus::Paid, 'delivery_method' => DeliveryMethod::Pickup, 'customer_name' => 'Sam Buyer']);
}

it('gives a customer a sample pack on their order, by reference and product', function (): void {
    $shop = StockedDrop::create(mince: 10);
    $order = paidPickup($shop);

    $this->server->tool(GiveFreeItemsTool::class, [
        'order' => $order->reference(),
        'items' => [['product_id' => $shop->mince->product_id, 'quantity' => 2]],
    ])->assertOk()->assertSee('"given":true');

    expect($order->refresh()->total)->toBe(16000)
        ->and($order->items()->where('free', true)->sum('quantity'))->toBe(2)
        ->and($shop->mince->refresh()->available)->toBe(8);
});

it('says why a free item can’t be given', function (): void {
    $shop = StockedDrop::create(mince: 1);

    $this->server->tool(GiveFreeItemsTool::class, [
        'order' => paidPickup($shop)->reference(),
        'items' => [['product_id' => $shop->mince->product_id, 'quantity' => 2]],
    ])->assertHasErrors(['Only 1 × 500g Beef Mince left.']);

    expect($shop->mince->refresh()->available)->toBe(1);
});

it('takes a free item back off, leaving what was paid for', function (): void {
    $shop = StockedDrop::create(mince: 10);
    $order = paidPickup($shop);
    app(GiveFreeItems::class)($order, [$shop->mince->id => 2]);

    $this->server->tool(RemoveFreeItemTool::class, ['order' => $order->reference(), 'product_id' => $shop->box->product_id])
        ->assertHasErrors(['has no free item']);
    $this->server->tool(RemoveFreeItemTool::class, ['order' => $order->reference(), 'product_id' => $shop->mince->product_id])
        ->assertOk()->assertSee('"removed":true');

    expect($order->items()->pluck('drop_item_id')->all())->toBe([$shop->box->id])
        ->and($shop->mince->refresh()->available)->toBe(10);
});
```

**Step 2: Run to see them fail**

Run: `php artisan test --compact tests/Feature/Mcp/FreeItemsToolsTest.php`
Expected: FAIL, tool classes not found.

**Step 3: `GiveFreeItemsTool`**

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Orders;

use App\Actions\Orders\GiveFreeItems;
use App\Exceptions\CannotChangeOrder;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Changes;
use App\Mcp\Support\OrderReference;
use App\Mcp\Support\Presenter;
use App\Models\DropItem;
use App\Models\Order;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Give a customer something free on their order, like a sample pack at pickup. Only when the owner asks. It comes out of the drop’s stock, so inventory stays right, but what they paid doesn’t change and nothing is texted or emailed. Works on Paid, Fulfilled and Cash owed orders, after the drop has closed too. Undo with remove-free-item.')]
#[IsDestructive(false)]
final class GiveFreeItemsTool extends Tool
{
    public function handle(Request $request, GiveFreeItems $giveFreeItems): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'order' => ['required', 'string', 'max:60'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $orders = OrderReference::matches($arguments->requireString('order'));

        if ($orders->count() !== 1) {
            return Response::error($orders->isEmpty() ? 'No order matches that reference.' : 'More than one order matches; use the full public id.');
        }

        $order = $orders->sole();
        $items = $request->get('items');

        return Changes::run('give-free-items', $request->all(), fn (): ResponseFactory => Response::structured([
            'given' => true,
            'order' => Presenter::order($giveFreeItems($order, $this->quantities($order, $items))),
        ]));
    }

    /**
     * The requested products as drop item ID => quantity, on the order's drop.
     *
     * @return array<int, int>
     *
     * @throws CannotChangeOrder
     */
    private function quantities(Order $order, mixed $items): array
    {
        $drop = $order->drop->loadMissing('items');
        $quantities = [];

        foreach (is_array($items) ? $items : [] as $line) {
            $productId = is_array($line) && is_numeric($line['product_id'] ?? null) ? (int) $line['product_id'] : 0;
            $item = $drop->items->first(fn (DropItem $item): bool => $item->product_id === $productId);

            if (! $item instanceof DropItem) {
                throw new CannotChangeOrder("Product {$productId} isn’t on {$drop->name}. Use get-drop to see what it sells.");
            }

            $quantities[$item->id] = ($quantities[$item->id] ?? 0) + (is_array($line) && is_numeric($line['quantity'] ?? null) ? (int) $line['quantity'] : 0);
        }

        return $quantities;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'order' => $schema->string()->description('Order reference like FL-7K2QXM.')->required(),
            'items' => $schema->array()->items($schema->object([
                'product_id' => $schema->integer()->description('A product on the order’s drop, from get-drop.')->required(),
                'quantity' => $schema->integer()->description('How many.')->required(),
            ]))->description('What to give.')->required(),
        ];
    }
}
```

**Step 4: `RemoveFreeItemTool`**

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Orders;

use App\Actions\Orders\TakeBackFreeItem;
use App\Mcp\Support\Arguments;
use App\Mcp\Support\Changes;
use App\Mcp\Support\OrderReference;
use App\Mcp\Support\Presenter;
use App\Models\OrderItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Take a free item back off an order, when one was given by mistake: its stock goes back on sale. Only free items can come off; what the customer paid for stays.')]
#[IsDestructive]
final class RemoveFreeItemTool extends Tool
{
    public function handle(Request $request, TakeBackFreeItem $takeBackFreeItem): Response|ResponseFactory
    {
        $arguments = Arguments::validate($request, [
            'order' => ['required', 'string', 'max:60'],
            'product_id' => ['required', 'integer'],
        ]);

        $orders = OrderReference::matches($arguments->requireString('order'));

        if ($orders->count() !== 1) {
            return Response::error($orders->isEmpty() ? 'No order matches that reference.' : 'More than one order matches; use the full public id.');
        }

        $order = $orders->sole();
        $productId = $arguments->requireInteger('product_id');
        $line = $order->items->filter(fn (OrderItem $line): bool => $line->free && $line->product_id === $productId)->last();

        if (! $line instanceof OrderItem) {
            return Response::error("Order {$order->reference()} has no free item with product id {$productId}. Use get-order to see its lines.");
        }

        return Changes::run('remove-free-item', $request->all(), function () use ($takeBackFreeItem, $line, $order): ResponseFactory {
            $takeBackFreeItem($line);

            return Response::structured(['removed' => true, 'order' => Presenter::order($order->refresh()->load(['items.product', 'drop']))]);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'order' => $schema->string()->description('Order reference like FL-7K2QXM.')->required(),
            'product_id' => $schema->integer()->description('The free item’s product id, from get-order’s lines.')->required(),
        ];
    }
}
```

**Step 5: Wire up**

- `Changes::run()`: add `CannotChangeOrder` to the caught exceptions (and its `use`).
- `FergusonLivestockServer`: import both tools, and register `GiveFreeItemsTool::class, RemoveFreeItemTool::class,` after `CancelCashOrderTool::class,`. Add this line to the instructions, right after the cash orders line:

```
- Free items: the owner sometimes gives a customer something extra, like a sample pack at pickup. give-free-items-tool puts it on their order at no charge, from the drop's stock, so inventory stays right; remove-free-item-tool undoes one. Only when the owner asks. Nothing is texted.
```

**Step 6: Run tests**

Run: `php artisan test --compact tests/Feature/Mcp`
Expected: PASS. If a test lists every registered tool, add the two new ones there.

**Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A app tests
git commit -m "Let Claude give and take back free items on an order"
```

---

### Task 6: Check everything

**Step 1:** `vendor/bin/pint --dirty --format agent`
**Step 2:** `composer analyse`. Expected: no errors. Fix any type complaints (for example, `waitingForLocks()` returning void) without suppressing them.
**Step 3:** `vendor/bin/rector process --dry-run --no-progress-bar`. Apply anything it suggests that fits the code.
**Step 4:** `php artisan test --compact tests/Feature/Checkout tests/Feature/Admin tests/Feature/Mcp tests/Feature/Sms`. Expected: PASS.
**Step 5:** Ask the owner to run the full suite (`php artisan test --compact`). Don't push without their go-ahead: pushes to main go live.
