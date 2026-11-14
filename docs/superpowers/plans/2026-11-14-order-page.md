# Order Page Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Give each order a private page (thank-you note, pickup or delivery details with a changeable delivery night,
receipt, "email me my order", feedback) and link it from the order text on a short domain, `fergs.au`.

**Architecture:** Each order gets a random 16-character `access_token`. A `customerOrder` route binding finds the order
by it and 404s checkouts that never became orders, so every page route shares one rule. The page is plain Blade built
from partials; each action is an invokable POST controller that redirects back to its section with a flash message.
The short domain is a `Route::domain()` group that redirects to the main site. Changing the night lives in an action
(`ChangeDeliveryNight`) that the page and controller share.

**Tech Stack:** Laravel 13, PHP 8.5, Postgres, Filament 5, laravel/mcp, Pest, Tailwind v4.

**Spec:** `docs/superpowers/specs/2026-11-14-order-page-design.md`

**Conventions to follow:** read `AGENTS.md`. PHPDoc over inline comments, curly braces always, explicit types.
Customer-facing copy uses curly apostrophes (’) and the owners' plain voice. `Model::shouldBeStrict()` is on outside
production: eager load what views use, and never mass-assign an attribute that isn't fillable. Pest helper functions
are global across the suite, so new helper names must be unique (`grep -rn "^function " tests` before adding one).
Run tests with `php artisan test --compact <path>`. If tests fail with phantom errors, another run may be sharing the
test database: see memory note "Shared test database collisions" and use a private `DB_DATABASE`. Commit messages are
plain sentences, like the recent history ("Let Claude give and take back free items on an order").

**Times used in tests:** the weekend drop delivers on Saturday 21 November 2026 and Sunday 22 November 2026. Night
changes close at 12pm Friday 20 November (Melbourne). Tests that depend on the clock travel to
`CarbonImmutable::parse('2026-11-18 09:00', 'Australia/Melbourne')` (a Wednesday) or to the cutoff.

---

### Task 1: Each order gets a secret for its page link

**Files:**
- Create: `database/migrations/<timestamp>_add_access_token_to_orders_table.php` (via artisan)
- Modify: `app/Models/Order.php`
- Modify: `tests/Pest.php` (shared helper `samsWeekendOrder()`)
- Test: `tests/Feature/Orders/OrderPageTest.php`

**Step 1: Add the shared test helper**

Add these imports to `tests/Pest.php` (keep them sorted with the existing ones):

```php
use App\Models\Drop;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
```

and append this function at the end of the file:

```php
/**
 * Sam's paid delivery of a 5kg box on the Saturday of a weekend drop (21 and 22 November 2026), to Ballarat.
 *
 * @param  array<string, mixed>  $attributes
 * @param  list<string>  $nights  the drop's delivery days
 */
function samsWeekendOrder(array $attributes = [], array $nights = ['2026-11-21', '2026-11-22']): Order
{
    $drop = Drop::factory()->open()->create(['delivery_days' => $nights]);
    $order = Order::factory()->for($drop)->create([
        'customer_name' => 'Sam Buyer',
        'email' => 'sam@example.com',
        'delivery_day' => '2026-11-21',
        'shipping_address' => ['line1' => '1 Main St', 'line2' => null, 'city' => 'Ballarat', 'state' => 'VIC', 'postal_code' => '3350', 'country' => 'AU'],
        'total' => 17500,
        ...$attributes,
    ]);

    OrderItem::factory()->for($order)->for(Product::factory()->box(5)->create(['name' => '5kg Beef Box']))->create(['quantity' => 1, 'unit_price' => 16000]);
    OrderItem::factory()->for($order)->for(Product::factory()->delivery()->create(['name' => 'Delivery']))->create(['quantity' => 1, 'unit_price' => 1500]);

    return $order;
}
```

Check the name is free first: `grep -rn "function samsWeekendOrder" tests` should print only this one.

**Step 2: Create the test file**

Run: `php artisan make:test --pest Orders/OrderPageTest --no-interaction`

Replace its contents with:

```php
<?php

declare(strict_types=1);

use App\Models\Order;

it('gives each order its own unguessable secret for its page link', function (): void {
    [$first, $second] = Order::factory()->count(2)->create()->all();

    expect($first->access_token)->toMatch('/^[A-Za-z0-9]{16}$/')
        ->and($second->access_token)->toMatch('/^[A-Za-z0-9]{16}$/')
        ->and($first->access_token)->not->toBe($second->access_token);
});
```

**Step 3: Run it to make sure it fails**

Run: `php artisan test --compact tests/Feature/Orders/OrderPageTest.php`
Expected: FAIL (`access_token` is null or the column doesn't exist).

**Step 4: Write the migration**

Run: `php artisan make:migration add_access_token_to_orders_table --table=orders --no-interaction`

Replace its contents with:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // The secret in the link to the customer's order page. Not public_id: Meta and Stripe see that.
            $table->string('access_token', 16)->nullable();
        });

        // A farm's worth of orders, so one update each is fine.
        foreach (DB::table('orders')->pluck('id') as $id) {
            DB::table('orders')->where('id', $id)->update(['access_token' => Str::random(16)]);
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('access_token', 16)->nullable(false)->change();
            $table->unique('access_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['access_token']);
            $table->dropColumn('access_token');
        });
    }
};
```

**Step 5: Generate the token as each order is created**

In `app/Models/Order.php`:

- Add `use Illuminate\Support\Str;`.
- Add `@property string $access_token` to the class docblock, after `@property string $public_id`.
- Add this constant and method right after `protected $attributes = [...]`:

```php
    /**
     * How long the secret in an order page's link is: about 95 random bits, hopeless to guess.
     */
    public const int ACCESS_TOKEN_LENGTH = 16;

    /**
     * Every order gets the secret for its page link as it's created.
     */
    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            $order->access_token ??= Str::random(self::ACCESS_TOKEN_LENGTH);
        });
    }
```

Do not add `access_token` to `#[Fillable]`: nothing should set it from input.

**Step 6: Run the migration locally and the test**

Run: `php artisan migrate --no-interaction` (local database, backfills existing orders)
Run: `php artisan test --compact tests/Feature/Orders/OrderPageTest.php`
Expected: PASS

**Step 7: Commit**

```bash
git add database/migrations/*_add_access_token_to_orders_table.php app/Models/Order.php tests/Pest.php tests/Feature/Orders/OrderPageTest.php
git commit -m "Give each order a secret for its page link"
```

---

### Task 2: The order page, kept private

**Files:**
- Create: `app/Http/Controllers/Orders/ShowOrderController.php`
- Create: `resources/views/pages/order-details.blade.php`
- Modify: `app/Providers/AppServiceProvider.php` (route binding, rate limiter)
- Modify: `routes/web.php`
- Modify: `app/Models/Order.php` (`pageUrl()`)
- Test: `tests/Feature/Orders/OrderPageTest.php`

**Step 1: Write the failing tests**

Add these imports to `tests/Feature/Orders/OrderPageTest.php`:

```php
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
```

and append:

```php
it('shows the order to whoever has its link, thanking them by name', function (): void {
    $order = samsWeekendOrder();

    $this->get($order->pageUrl())
        ->assertOk()
        ->assertSeeInOrder(['Thanks, Sam!', 'We’re getting your order ready', 'Thank you for supporting our small family farm']);
});

it('thanks them in words that fit where the order is up to', function (array $attributes, string $subheading): void {
    $this->get(samsWeekendOrder($attributes)->pageUrl())->assertOk()->assertSee($subheading);
})->with([
    'payment clearing' => [['status' => OrderStatus::Processing], 'Your payment is on its way'],
    'delivered' => [['status' => OrderStatus::Fulfilled], 'Your order has been delivered'],
    'collected' => [['status' => OrderStatus::Fulfilled, 'delivery_method' => DeliveryMethod::Pickup, 'delivery_day' => null, 'shipping_address' => null], 'You’ve collected your order'],
]);

it('keeps the page out of search results, caches and Meta’s pixel', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    enableMetaAds();
    $order = samsWeekendOrder();

    $response = $this->get($order->pageUrl())
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('<meta name="robots" content="noindex, nofollow">', escape: false)
        ->assertDontSee('fbq(', false);

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

it('is a plain 404 for a wrong link', function (): void {
    $this->get('/orders/'.str_repeat('a', Order::ACCESS_TOKEN_LENGTH))->assertNotFound();
});

it('is a 404 for a checkout that never became an order', function (OrderStatus $status): void {
    $order = Order::factory()->pending()->create(['status' => $status]);

    $this->get($order->pageUrl())->assertNotFound();
})->with([OrderStatus::Pending, OrderStatus::Expired, OrderStatus::Failed]);
```

**Step 2: Run them to make sure they fail**

Run: `php artisan test --compact tests/Feature/Orders/OrderPageTest.php`
Expected: FAIL (`pageUrl()` undefined / route `orders.show` not defined).

**Step 3: Add `pageUrl()` to the order**

In `app/Models/Order.php`, after `reference()`:

```php
    /**
     * The customer's own page for this order. The secret in it is the only key, so it only ever goes to the customer.
     */
    public function pageUrl(): string
    {
        return route('orders.show', ['customerOrder' => $this->access_token]);
    }
```

**Step 4: Bind `customerOrder` and add the rate limiter**

In `app/Providers/AppServiceProvider.php`, add imports `use App\Enums\OrderStatus;`, `use App\Models\Order;` and
`use Illuminate\Support\Facades\Route;`. At the end of `boot()`, after the `mcp` rate limiter, add:

```php
        // Guessing a 16-character link is hopeless, and a customer opens their page a handful of times.
        RateLimiter::for('order-page', fn (Request $request) => Limit::perMinute(30)->by((string) $request->ip()));

        // Customers' order pages find their order by the secret in the link. A checkout that never became an order has
        // no page, so it's a 404 like a wrong link.
        Route::bind('customerOrder', fn (string $token): Order => Order::query()
            ->where('access_token', $token)
            ->whereIn('status', OrderStatus::placed())
            ->firstOrFail());
```

**Step 5: Add the route**

In `routes/web.php`, add `use App\Http\Controllers\Orders\ShowOrderController;` to the imports, and after the
early-access group add:

```php
// A customer's own order page, linked from their order text and email. The secret in its address is the only key, so
// it's kept out of search results and caches, and throttled so guessing is pointless. See the customerOrder binding in
// AppServiceProvider.
Route::middleware(['throttle:order-page', PreventIndexing::class])->prefix('orders/{customerOrder}')->group(function (): void {
    Route::get('/', ShowOrderController::class)->middleware('cache.headers:private;no_store')->name('orders.show');
});
```

**Step 6: Write the controller**

Run: `php artisan make:controller Orders/ShowOrderController --invokable --no-interaction`

Replace its contents with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Contracts\View\View;

/**
 * A customer's own page for their order, found by the secret in its link.
 */
class ShowOrderController extends Controller
{
    public function __invoke(Order $customerOrder): View
    {
        return view('pages.order-details', ['order' => $customerOrder->load(['items.product', 'drop'])]);
    }
}
```

**Step 7: Write the view**

Create `resources/views/pages/order-details.blade.php`:

```blade
@php
    use App\Enums\DeliveryMethod;
    use App\Enums\OrderStatus;

    /** @var \App\Models\Order $order */
    $firstName = $order->firstName() ?? 'friend';
    $subheading = match (true) {
        $order->status === OrderStatus::Processing => 'Your payment is on its way',
        $order->status === OrderStatus::Fulfilled && $order->delivery_method === DeliveryMethod::Delivery => 'Your order has been delivered',
        $order->status === OrderStatus::Fulfilled => 'You’ve collected your order',
        default => 'We’re getting your order ready',
    };
@endphp

{{-- The secret in this page's address is the key to the order, so the page stays away from Meta and search engines. --}}
<x-layouts.app
    :title="'Your order '.$order->reference().' | Ferguson Livestock'"
    description="Your Ferguson Livestock order."
    robots="noindex, nofollow"
    :fonts="['source-sans', 'cormorant', 'caveat']"
    :ad-tracking="false"
>
    <div class="bg-cream-dark px-6 py-16 print:bg-white print:py-0">
        <x-confirmation-letter :heading="'Thanks, '.$firstName.'!'" :subheading="$subheading">
            @if ($order->status === OrderStatus::Processing)
                <p>Your bank payment usually takes a few business days to clear. We’re holding your order in the meantime.</p>
            @elseif ($order->status === OrderStatus::Fulfilled)
                <p>Thank you for supporting our small family farm. We hope you enjoy every bit of it, and we’d love to hear how it went at the bottom of this page.</p>
            @else
                <p>Thank you for supporting our small family farm! We’re packing everything fresh and vacuum-sealing it, ready for you.</p>
            @endif
        </x-confirmation-letter>
    </div>
</x-layouts.app>
```

**Step 8: Run the tests**

Run: `php artisan test --compact tests/Feature/Orders/OrderPageTest.php`
Expected: PASS

**Step 9: Commit**

```bash
git add app/Http/Controllers/Orders/ShowOrderController.php resources/views/pages/order-details.blade.php app/Providers/AppServiceProvider.php routes/web.php app/Models/Order.php tests/Feature/Orders/OrderPageTest.php
git commit -m "Add a private page for each order"
```

---

### Task 3: The short domain

**Files:**
- Modify: `config/shop.php`
- Modify: `.env.example`
- Modify: `phpunit.xml`
- Modify: `routes/web.php`
- Modify: `app/Models/Order.php` (`shortLink()`)
- Test: `tests/Feature/Orders/OrderPageTest.php`

**Step 1: Write the failing tests**

Add `use Illuminate\Support\Facades\URL;` to the imports of `tests/Feature/Orders/OrderPageTest.php`, and append:

```php
describe('short links', function (): void {
    it('sends a link on the short domain to the order page', function (): void {
        $order = samsWeekendOrder();

        $this->get("https://fergs.au/{$order->access_token}")
            ->assertRedirect("https://fergusonlivestock.com.au/orders/{$order->access_token}");
    });

    it('sends anything else on the short domain to the main site', function (string $path): void {
        $this->get("https://fergs.au{$path}")->assertRedirect('https://fergusonlivestock.com.au');
    })->with(['the bare domain' => '/', 'a page' => '/order', 'a too-short link' => '/abc123']);

    it('gives texts the short link', function (): void {
        $order = samsWeekendOrder();

        expect($order->shortLink())->toBe("fergs.au/{$order->access_token}");
    });

    it('gives texts the page’s full address when there’s no short domain', function (): void {
        config(['shop.short_domain' => null]);
        URL::useOrigin('https://fergusonlivestock.com.au');
        $order = samsWeekendOrder();

        expect($order->shortLink())->toBe("fergusonlivestock.com.au/orders/{$order->access_token}");
    });
});
```

**Step 2: Run them to make sure they fail**

Run: `php artisan test --compact tests/Feature/Orders/OrderPageTest.php --filter="short"`
Expected: FAIL (404s on `fergs.au`, `shortLink()` undefined).

**Step 3: Add the setting**

In `config/shop.php`, after `'url' => ...`:

```php
    // A short domain that sends order texts' links on to the order page, so texts stay short: "fergs.au". Leave it
    // empty to text the order page's full address instead. Set it only once the domain serves this app.
    'short_domain' => env('SHOP_SHORT_DOMAIN'),
```

In `.env.example`, below `SHOP_URL=...` on line 24:

```
# The short domain in order texts (e.g. fergs.au). Empty texts the full order page address.
SHOP_SHORT_DOMAIN=
```

In `phpunit.xml`, after `<env name="SHOP_URL" .../>`:

```xml
        <env name="SHOP_SHORT_DOMAIN" value="fergs.au"/>
```

**Step 4: Add the short domain's routes**

In `routes/web.php`, add `use App\Models\Order;` to the imports. Put this block **first**, right after the imports and
before the response-cached group, so every request to the short domain is handled here:

```php
// The short domain in order texts. Its links go on to the order page on the main site, and anything else goes to the
// main site's home page. Registered first, so the main site's routes never answer on it.
if (filled(config('shop.short_domain'))) {
    Route::domain(config()->string('shop.short_domain'))->middleware('throttle:order-page')->group(function (): void {
        Route::get('/{token}', fn (string $token) => redirect()->away(config()->string('shop.url').route('orders.show', ['customerOrder' => $token], absolute: false)))
            ->where('token', '[A-Za-z0-9]{'.Order::ACCESS_TOKEN_LENGTH.'}')
            ->name('short-link');
        Route::get('/{path?}', fn () => redirect()->away(config()->string('shop.url')))->where('path', '.*');
    });
}
```

The redirect is built from `shop.url`, not `route()` alone, because `route()` would use the short domain as its host.

**Step 5: Add `shortLink()` to the order**

In `app/Models/Order.php`, after `pageUrl()`:

```php
    /**
     * The page's address as order texts give it, without the scheme: "fergs.au/7Kp2Qx9mZr4TbW3n" on the short
     * domain, or the full address when there isn't one.
     */
    public function shortLink(): string
    {
        $shortDomain = config('shop.short_domain');

        return is_string($shortDomain) && $shortDomain !== ''
            ? "{$shortDomain}/{$this->access_token}"
            : (string) preg_replace('#^https?://(www\.)?#', '', $this->pageUrl());
    }
```

**Step 6: Run the tests**

Run: `php artisan test --compact tests/Feature/Orders/OrderPageTest.php`
Expected: PASS

**Step 7: Commit**

```bash
git add config/shop.php .env.example phpunit.xml routes/web.php app/Models/Order.php tests/Feature/Orders/OrderPageTest.php
git commit -m "Send links on a short domain to the order page"
```

---

### Task 4: The order text links the page

**Files:**
- Modify: `app/Sms/OrderConfirmationText.php`
- Modify: `routes/web.php` (the `/farm` comment)
- Test: `tests/Feature/Sms/OrderConfirmationTextTest.php`

**Step 1: Update the tests to the new texts**

In `tests/Feature/Sms/OrderConfirmationTextTest.php`:

Replace the pickup test's expectation (in `it('tells a pickup customer what they ordered, ...')`) with:

```php
    expect($text->text)->toBe("Ferguson Livestock: Hi Alec, thanks for your order {$order->reference()}: 1 x 10kg Beef Box. Pick it up from 306 Snake Valley-Chepstowe Rd, Snake Valley, Saturday or Sunday between 10am and 4pm. Your order & map: fergs.au/{$order->access_token} Questions? Just reply here. - Daniel & Tahlia")
        ->and($text->isGsm())->toBeTrue()
        ->and($text->segments())->toBe(2);
```

Replace the delivery test's expectation (in `it('tells a delivery customer the night they chose, ...')`) with:

```php
    expect($text->text)->toBe("Ferguson Livestock: Hi Paul, thanks for your order {$order->reference()}: 1 x 10kg Beef Box. We'll deliver it after 4pm on Sunday, and we'll text you closer to the time. Your order: fergs.au/{$order->access_token} Questions? Just reply here. - Daniel & Tahlia")
        ->and($text->segments())->toBe(2);
```

Rename the last test, since pickup texts no longer carry the map link (the order page does):

```php
it('sends the farm’s map link to Google Maps', function (): void {
```

Append:

```php
it('links the first order’s page when one text covers several, since that page lists the rest', function (): void {
    $drop = weekendDrop();
    $first = orderOf($drop, 'Sam Buyer', ['10kg Beef Box' => 1]);
    $second = orderOf($drop, 'Sam Buyer', ['500g Beef Mince' => 2]);

    expect(OrderConfirmationText::for([$first, $second])->text)
        ->toContain("Your order & map: fergs.au/{$first->access_token} ")
        ->not->toContain($second->access_token);
});
```

**Step 2: Run them to make sure they fail**

Run: `php artisan test --compact tests/Feature/Sms/OrderConfirmationTextTest.php`
Expected: FAIL (texts still say `Map: fergusonlivestock.com.au/farm`).

**Step 3: Put the link in the text**

In `app/Sms/OrderConfirmationText.php`, replace the `$when = ...;` statement with:

```php
        $when = $first->delivery_method === DeliveryMethod::Delivery
            ? "We'll deliver {$it} after 4pm on ".self::deliveryNight($orders).", and we'll text you closer to the time. Your order: {$first->shortLink()}"
            : 'Pick '.$it.' up from '.config()->string('shop.location.street').', '.config()->string('shop.location.locality').', '
                .($first->drop->deliveryWeekdays() ?? config()->string('shop.pickup.days')).' between '
                .str_replace(' to ', ' and ', config()->string('shop.pickup.hours')).". Your order & map: {$first->shortLink()}";
```

Delete the now-unused `mapLink()` method. Add this paragraph to the class docblock, after the first paragraph:

```
 * It links the first order's page (see Order::shortLink()), which has the farm's map and lists their other orders the
 * text covers.
```

In `routes/web.php`, change the comment above the `/farm` route to:

```php
// The farm on Google Maps, linked from order pages. A temporary redirect, so where it points can change.
```

**Step 4: Run the tests**

Run: `php artisan test --compact tests/Feature/Sms/OrderConfirmationTextTest.php tests/Feature/Mcp/OrderTextsToolsTest.php tests/Feature/Admin/OrderTextResourceTest.php`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Sms/OrderConfirmationText.php routes/web.php tests/Feature/Sms/OrderConfirmationTextTest.php
git commit -m "Link the order page from the order text"
```

---

### Task 5: The confirmation email links the page

**Files:**
- Modify: `resources/views/mail/orders/confirmation.blade.php`
- Test: `tests/Feature/Checkout/OrderEmailsTest.php`

**Step 1: Write the failing test**

Append to `tests/Feature/Checkout/OrderEmailsTest.php`:

```php
it('links the customer to their order page', function (): void {
    $order = paidDelivery();

    (new OrderConfirmation($order))
        ->assertSeeInHtml('View your order')
        ->assertSeeInHtml($order->pageUrl());
});
```

**Step 2: Run it to make sure it fails**

Run: `php artisan test --compact tests/Feature/Checkout/OrderEmailsTest.php --filter="order page"`
Expected: FAIL

**Step 3: Add the button**

In `resources/views/mail/orders/confirmation.blade.php`, between the `@endif` that closes the delivery/pickup block and
the `Questions? Reply to this email ...` line, add (no indentation: it's Markdown):

```blade

<x-mail::button :url="$order->pageUrl()">
View your order
</x-mail::button>

```

**Step 4: Run the email tests**

Run: `php artisan test --compact tests/Feature/Checkout/OrderEmailsTest.php`
Expected: PASS

**Step 5: Commit**

```bash
git add resources/views/mail/orders/confirmation.blade.php tests/Feature/Checkout/OrderEmailsTest.php
git commit -m "Link the order page from the confirmation email"
```

---

### Task 6: Pickup or delivery details, the receipt and the customer's other orders

**Files:**
- Create: `resources/views/pages/order-details/_fulfilment.blade.php`
- Create: `resources/views/pages/order-details/_receipt.blade.php`
- Create: `resources/views/pages/order-details/_also-in-text.blade.php`
- Create: `resources/views/pages/order-details/_questions.blade.php`
- Modify: `resources/views/pages/order-details.blade.php`
- Modify: `app/Http/Controllers/Orders/ShowOrderController.php`
- Modify: `resources/views/components/header.blade.php`, `resources/views/components/footer.blade.php` (`print:hidden`)
- Modify: `config/shop.php` (the street's comment)
- Test: `tests/Feature/Orders/OrderPageTest.php`

**Step 1: Write the failing tests**

Add these imports to `tests/Feature/Orders/OrderPageTest.php`:

```php
use App\Enums\SmsStatus;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SmsMessage;
```

and append:

```php
it('tells a delivery customer their night and address', function (): void {
    $this->get(samsWeekendOrder()->pageUrl())
        ->assertOk()
        ->assertSeeInOrder(['Delivery', 'Saturday night, 21 November', '1 Main St', 'Ballarat 3350']);
});

it('tells a pickup customer where and when to collect, with the farm’s map', function (): void {
    $order = samsWeekendOrder(['delivery_method' => DeliveryMethod::Pickup, 'delivery_day' => null, 'shipping_address' => null]);

    $this->get($order->pageUrl())
        ->assertOk()
        ->assertSeeInOrder(['Farm pickup', '306 Snake Valley-Chepstowe Rd, Snake Valley, Saturday or Sunday, 10am to 4pm', 'Open in Google Maps'])
        ->assertSee(route('farm'));
});

it('gives a receipt, with what was given free and anything refunded', function (): void {
    $order = samsWeekendOrder(['amount_refunded' => 1500]);
    OrderItem::factory()->for($order)->for(Product::factory()->create(['name' => '500g Beef Mince']))->free()->create(['quantity' => 2]);

    $this->get($order->pageUrl())
        ->assertOk()
        ->assertSeeInOrder(['Receipt', $order->reference(), '5kg Beef Box', '$160', 'Delivery', '$15', '2 × 500g Beef Mince', 'Free', 'Total', '$175', 'Refunded', '−$15']);
});

it('shows what the customer typed as plain text', function (): void {
    $order = samsWeekendOrder([
        'customer_name' => '<script>alert(1)</script>',
        'shipping_address' => ['line1' => '<b>1 Main St</b>', 'line2' => null, 'city' => 'Ballarat', 'state' => 'VIC', 'postal_code' => '3350', 'country' => 'AU'],
    ]);

    $this->get($order->pageUrl())
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('<b>1 Main St</b>', false)
        ->assertSee('&lt;script&gt;', false);
});

it('links the customer’s other orders that the same text covered', function (): void {
    $text = SmsMessage::factory()->orderConfirmation(SmsStatus::Sent)->create();
    $order = samsWeekendOrder(['confirmation_sms_message_id' => $text->id]);
    $sameText = Order::factory()->for($order->drop)->create(['confirmation_sms_message_id' => $text->id]);
    $otherText = Order::factory()->for($order->drop)->create(['confirmation_sms_message_id' => SmsMessage::factory()->orderConfirmation()->create()->id]);

    $this->get($order->pageUrl())
        ->assertOk()
        ->assertSeeInOrder(['Your other orders', $sameText->reference()])
        ->assertSee($sameText->access_token)
        ->assertDontSee($otherText->reference());
});

it('lets the customer text the farm about their order', function (): void {
    $order = samsWeekendOrder();

    $this->get($order->pageUrl())
        ->assertOk()
        ->assertSee('sms:+61427458706?&body='.rawurlencode("Hi, about my order {$order->reference()}: "))
        ->assertSee('tel:+61427458706');
});
```

**Step 2: Run them to make sure they fail**

Run: `php artisan test --compact tests/Feature/Orders/OrderPageTest.php`
Expected: the six new tests FAIL.

**Step 3: Pass the other orders from the controller**

Replace `app/Http/Controllers/Orders/ShowOrderController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;

/**
 * A customer's own page for their order, found by the secret in its link.
 */
class ShowOrderController extends Controller
{
    public function __invoke(Order $customerOrder): View
    {
        return view('pages.order-details', [
            'order' => $customerOrder->load(['items.product', 'drop']),
            'alsoInText' => $this->alsoInText($customerOrder),
        ]);
    }

    /**
     * The customer's other orders that their order text covered. The text only links this one, so its page links them.
     *
     * @return Collection<int, Order>
     */
    private function alsoInText(Order $order): Collection
    {
        if ($order->confirmation_sms_message_id === null) {
            return new Collection;
        }

        return Order::query()
            ->where('confirmation_sms_message_id', $order->confirmation_sms_message_id)
            ->whereKeyNot($order->id)
            ->whereIn('status', OrderStatus::placed())
            ->orderBy('id')
            ->get();
    }
}
```

**Step 4: Write the partials**

Create `resources/views/pages/order-details/_fulfilment.blade.php`:

```blade
{{-- How the order reaches the customer. Expects $order (with its drop) and $textUsUrl. --}}
@php
    use App\Enums\DeliveryMethod;

    /** @var \App\Models\Order $order */
    $address = $order->shipping_address;
@endphp

<section id="delivery" aria-labelledby="delivery-heading" class="border-t border-gray-100 pt-6">
    @if ($order->delivery_method === DeliveryMethod::Delivery)
        <h2 id="delivery-heading" class="mb-3 font-display text-2xl font-semibold text-forest">Delivery</h2>
        <p>We’ll bring it to you after 4pm on <strong>{{ $order->deliveryNight() }}</strong>, and we’ll text you closer to the time. Everything arrives vacuum-sealed in insulated packaging, ready for the freezer or fridge.</p>

        @if ($address !== null)
            <address class="mt-3 not-italic">
                {{ $address['line1'] ?? '' }}@if (filled($address['line2'] ?? null)), {{ $address['line2'] }}@endif<br>
                {{ $address['city'] ?? '' }} {{ $address['postal_code'] ?? '' }}
            </address>
        @endif
    @else
        <h2 id="delivery-heading" class="mb-3 font-display text-2xl font-semibold text-forest">Farm pickup</h2>
        <p>Collect it from {{ config('shop.location.street') }}, {{ config('shop.location.locality') }}, {{ $order->drop->pickupTimes() }}.</p>

        <div class="mt-4 print:hidden">
            <x-button :href="route('farm')" variant="outline">
                Open in Google Maps
                <x-svg-icon name="arrow-right" />
            </x-button>
        </div>
    @endif

    <p class="mt-4 text-base print:hidden">Need to switch between pickup and delivery, or change your address? <a class="underline" href="{{ $textUsUrl }}">Text us</a> and we’ll sort it out.</p>
</section>
```

Create `resources/views/pages/order-details/_receipt.blade.php`:

```blade
{{-- What the order cost. Expects $order with items.product. Prints on its own; the rest of the page hides. --}}
@php
    use App\Enums\OrderStatus;
    use App\Enums\PaymentMethod;
    use App\Support\Money;

    /** @var \App\Models\Order $order */
    $payment = match (true) {
        $order->status === OrderStatus::CashOwed => 'Cash, still to pay',
        $order->payment_method === PaymentMethod::Cash => 'Paid in cash',
        $order->status === OrderStatus::Processing => 'Bank payment, clearing',
        default => 'Paid online',
    };
@endphp

<section aria-labelledby="receipt-heading" class="border-t border-gray-100 pt-6 text-base">
    <div class="mb-3 flex items-baseline justify-between gap-4">
        <h2 id="receipt-heading" class="font-display text-2xl font-semibold text-forest">Receipt</h2>
        <button type="button" onclick="window.print()" class="cursor-pointer text-sm font-semibold text-forest underline print:hidden">Print or save as PDF</button>
    </div>

    <p class="mb-3">Order {{ $order->reference() }} · {{ ($order->paid_at ?? $order->created_at)->setTimezone(config('shop.timezone'))->format('j F Y') }} · {{ $payment }}</p>

    <table class="w-full">
        <caption class="sr-only">What you ordered</caption>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td class="py-1 pr-4">{{ $item->quantity > 1 ? $item->quantity.' × ' : '' }}{{ $item->product->name }}</td>
                    <td class="py-1 text-right">{{ $item->free ? 'Free' : Money::format($item->lineTotal()) }}</td>
                </tr>
            @endforeach
            <tr class="font-semibold text-forest">
                <td class="pt-3 pr-4">Total</td>
                <td class="pt-3 text-right">{{ Money::format($order->total) }}</td>
            </tr>
            @if ($order->amount_refunded > 0)
                <tr>
                    <td class="py-1 pr-4">Refunded</td>
                    <td class="py-1 text-right">−{{ Money::format($order->amount_refunded) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <p class="mt-3 text-sm text-gray-600">{{ config('shop.name') }} · {{ config('shop.location.locality') }}, {{ config('shop.location.region') }} · {{ config('shop.email') }}</p>
</section>
```

Create `resources/views/pages/order-details/_also-in-text.blade.php`:

```blade
{{-- The customer's other orders their order text covered. Expects $alsoInText. --}}
@if ($alsoInText->isNotEmpty())
    <section aria-labelledby="also-heading" class="border-t border-gray-100 pt-6 text-base print:hidden">
        <h2 id="also-heading" class="mb-3 font-display text-2xl font-semibold text-forest">Your other orders</h2>
        <p>Our text was about these too:</p>
        <ul class="mt-2 list-disc pl-6">
            @foreach ($alsoInText as $other)
                <li><a class="underline" href="{{ $other->pageUrl() }}">Order {{ $other->reference() }}</a></li>
            @endforeach
        </ul>
    </section>
@endif
```

Create `resources/views/pages/order-details/_questions.blade.php`:

```blade
{{-- Ways to reach the farm. Expects $textUsUrl. --}}
<section aria-labelledby="questions-heading" class="border border-forest/5 bg-white p-8 shadow-xl">
    <h2 id="questions-heading" class="mb-3 font-display text-2xl font-semibold text-forest">Questions?</h2>
    <p class="mb-5">Text or call us on {{ config('shop.phone.display') }}. It’s just the two of us, so we’ll get back to you as soon as we can.</p>

    <div class="flex flex-wrap gap-3">
        <x-button :href="$textUsUrl">
            <x-svg-icon name="message" />
            Text us
        </x-button>
        <x-button :href="'tel:'.config('shop.phone.international')" variant="outline">Call {{ config('shop.phone.display') }}</x-button>
    </div>
</section>
```

**Step 5: Put the partials on the page**

In `resources/views/pages/order-details.blade.php`:

Add to the `@php` block, after `$subheading = ...;`:

```php
    // Opens their messages app with a text to the farm already started, on iPhones and Android phones alike.
    $textUsUrl = 'sms:'.config('shop.phone.international').'?&body='.rawurlencode("Hi, about my order {$order->reference()}: ");
```

Replace the `</x-confirmation-letter>` line with:

```blade

            @include('pages.order-details._fulfilment')
            @include('pages.order-details._receipt')
            @include('pages.order-details._also-in-text')

            <x-slot:actions>
                <div class="space-y-8 print:hidden">
                    @include('pages.order-details._questions')
                </div>
            </x-slot:actions>
        </x-confirmation-letter>
```

**Step 6: Print only the letter**

In `resources/views/components/header.blade.php` line 9, add `print:hidden` to the header's classes:

```blade
<header class="sticky top-0 z-50 border-b border-forest/10 bg-cream print:hidden">
```

In `resources/views/components/footer.blade.php` line 11:

```blade
<footer class="bg-forest py-14 text-cream print:hidden">
```

In `config/shop.php`, change the street's comment to:

```php
        // Where customers collect farm pickups. Given in their order text and on their order page, not on the public site.
```

**Step 7: Run the tests**

Run: `php artisan test --compact tests/Feature/Orders/OrderPageTest.php tests/Feature/LayoutTest.php`
Expected: PASS

**Step 8: Commit**

```bash
git add resources/views/pages/order-details.blade.php resources/views/pages/order-details app/Http/Controllers/Orders/ShowOrderController.php resources/views/components/header.blade.php resources/views/components/footer.blade.php config/shop.php tests/Feature/Orders/OrderPageTest.php
git commit -m "Show pickup or delivery details, a receipt and the customer's other orders on the order page"
```

---

### Task 7: Change the delivery night

**Files:**
- Create: `app/Actions/Orders/ChangeDeliveryNight.php`
- Create: `app/Mail/DeliveryNightChanged.php`
- Create: `resources/views/mail/orders/night-changed.blade.php`
- Create: `app/Http/Requests/ChangeDeliveryNightRequest.php`
- Create: `app/Http/Controllers/Orders/ChangeDeliveryNightController.php`
- Create: `resources/views/pages/order-details/_change-night.blade.php`
- Modify: `app/Actions/Orders/OrderConfirmationTexts.php` (`rewriteWaiting()`)
- Modify: `app/Exceptions/CannotChangeOrder.php` (docblock)
- Modify: `app/Http/Controllers/Orders/ShowOrderController.php`
- Modify: `resources/views/pages/order-details/_fulfilment.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Orders/ChangeDeliveryNightTest.php`

**Step 1: Write the failing tests**

Run: `php artisan make:test --pest Orders/ChangeDeliveryNightTest --no-interaction`

Replace its contents with:

```php
<?php

declare(strict_types=1);

use App\Actions\Orders\ChangeDeliveryNight;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\SmsStatus;
use App\Mail\DeliveryNightChanged;
use App\Models\Order;
use App\Models\SmsMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => travelTo(CarbonImmutable::parse('2026-11-18 09:00', 'Australia/Melbourne')));

/**
 * Ask to move the order to this night from its page.
 */
function changeNightTo(Order $order, ?string $night): \Illuminate\Testing\TestResponse
{
    return test()->post(route('orders.night', ['customerOrder' => $order->access_token]), $night === null ? [] : ['night' => $night]);
}

it('moves a delivery to another of the drop’s nights, and tells the farm', function (): void {
    Mail::fake();
    $order = samsWeekendOrder();

    changeNightTo($order, '2026-11-22')
        ->assertRedirect($order->pageUrl().'#delivery')
        ->assertSessionHas('order-page.night', 'Done. We’ll deliver on Sunday night, 22 November.');

    expect($order->refresh()->delivery_day?->toDateString())->toBe('2026-11-22');
    Mail::assertQueued(DeliveryNightChanged::class, fn (DeliveryNightChanged $mail): bool => $mail->hasTo(config()->string('shop.email'))
        && $mail->previousNight === 'Saturday night, 21 November');
});

it('takes either night when the customer says either suits', function (): void {
    Mail::fake();
    $order = samsWeekendOrder();

    changeNightTo($order, ChangeDeliveryNight::EITHER)->assertSessionHas('order-page.night', 'Done. We’ll deliver on Saturday or Sunday night.');

    expect($order->refresh()->delivery_day)->toBeNull();
});

it('still changes the night until 12pm the day before the earlier night', function (): void {
    Mail::fake();
    travelTo(CarbonImmutable::parse('2026-11-20 11:59', 'Australia/Melbourne'));
    $order = samsWeekendOrder();

    changeNightTo($order, '2026-11-22')->assertSessionHasNoErrors();

    expect($order->refresh()->delivery_day?->toDateString())->toBe('2026-11-22');
});

it('says it’s too late from 12pm the day before the earlier night', function (?string $from, string $to): void {
    Mail::fake();
    travelTo(CarbonImmutable::parse('2026-11-20 12:00', 'Australia/Melbourne'));
    $order = samsWeekendOrder(['delivery_day' => $from]);

    changeNightTo($order, $to)
        ->assertRedirect($order->pageUrl().'#delivery')
        ->assertSessionHasErrorsIn('night', ['night' => 'It’s too late to change your night here. Text us on 0427 458 706 and we’ll see what we can do.']);

    expect($order->refresh()->delivery_day?->toDateString())->toBe($from);
    Mail::assertNothingQueued();
})->with([
    'Saturday to Sunday' => ['2026-11-21', '2026-11-22'],
    'Sunday to either night, which counts as Saturday' => ['2026-11-22', ChangeDeliveryNight::EITHER],
]);

it('refuses a night the drop doesn’t deliver on', function (): void {
    Mail::fake();
    $order = samsWeekendOrder();

    changeNightTo($order, '2026-11-23')->assertSessionHasErrorsIn('night', ['night' => 'That isn’t one of this drop’s delivery nights. Choose another.']);

    expect($order->refresh()->delivery_day?->toDateString())->toBe('2026-11-21');
    Mail::assertNothingQueued();
});

it('asks the customer to choose a night', function (): void {
    changeNightTo(samsWeekendOrder(), null)->assertSessionHasErrorsIn('night', ['night' => 'Choose a night.']);
});

it('refuses a pickup, which has no night to change', function (): void {
    $order = samsWeekendOrder(['delivery_method' => DeliveryMethod::Pickup, 'delivery_day' => null, 'shipping_address' => null]);

    changeNightTo($order, '2026-11-22')->assertSessionHasErrorsIn('night', ['night' => 'This order is a farm pickup, so there’s no delivery night to change.']);
});

it('refuses an order that’s been delivered', function (): void {
    $order = samsWeekendOrder(['status' => OrderStatus::Fulfilled]);

    changeNightTo($order, '2026-11-22')->assertSessionHasErrorsIn('night', ['night' => 'It’s too late to change your night here. Text us on 0427 458 706 and we’ll see what we can do.']);
});

it('can’t change a checkout that never became an order', function (): void {
    changeNightTo(samsWeekendOrder(['status' => OrderStatus::Expired]), '2026-11-22')->assertNotFound();
});

it('rewrites the order text if it’s still waiting for the morning', function (): void {
    Mail::fake();
    $text = SmsMessage::factory()->orderConfirmation(SmsStatus::Queued)->create();
    $order = samsWeekendOrder(['confirmation_sms_message_id' => $text->id]);

    app(ChangeDeliveryNight::class)($order->load('drop'), '2026-11-22');

    expect($text->refresh()->body)->toContain("We'll deliver it after 4pm on Sunday");
});

it('leaves an order text that’s already gone alone', function (): void {
    Mail::fake();
    $text = SmsMessage::factory()->orderConfirmation(SmsStatus::Sent)->create();
    $order = samsWeekendOrder(['confirmation_sms_message_id' => $text->id]);

    app(ChangeDeliveryNight::class)($order->load('drop'), '2026-11-22');

    expect($text->refresh()->body)->toBe('Ferguson Livestock: Hi Sam, thanks for your order FL-ABC123: 1 x 5kg Beef Box.');
});

it('tells the farm which night the delivery moved from and to', function (): void {
    $order = samsWeekendOrder(['delivery_day' => '2026-11-22']);

    (new DeliveryNightChanged($order, 'Saturday night, 21 November'))
        ->assertHasSubject("{$order->reference()} moved to Sunday night, 22 November")
        ->assertSeeInOrderInHtml(['Sam Buyer', 'Saturday night, 21 November', 'Sunday night, 22 November']);
});

it('offers the drop’s other nights on the order page until changes close', function (): void {
    $this->get(samsWeekendOrder()->pageUrl())
        ->assertOk()
        ->assertSeeInOrder(['Need a different night?', 'Saturday night, 21 November', 'Sunday night, 22 November', 'Either night suits me', 'Change night']);
});

it('doesn’t offer to change the night when there’s nothing to change it to', function (Closure $makeOrder): void {
    $this->get($makeOrder()->pageUrl())->assertOk()->assertDontSee('Need a different night?');
})->with([
    'a pickup' => [fn (): Order => samsWeekendOrder(['delivery_method' => DeliveryMethod::Pickup, 'delivery_day' => null, 'shipping_address' => null])],
    'a one-night drop' => [fn (): Order => samsWeekendOrder(nights: ['2026-11-21'])],
    'already delivered' => [fn (): Order => samsWeekendOrder(['status' => OrderStatus::Fulfilled])],
    'from 12pm the day before' => [function (): Order {
        travelTo(CarbonImmutable::parse('2026-11-20 12:00', 'Australia/Melbourne'));

        return samsWeekendOrder();
    }],
]);
```

Check `changeNightTo` is a free name: `grep -rn "function changeNightTo" tests`.

**Step 2: Run them to make sure they fail**

Run: `php artisan test --compact tests/Feature/Orders/ChangeDeliveryNightTest.php`
Expected: FAIL (route `orders.night` and the classes don't exist).

**Step 3: Rewrite a waiting text**

In `app/Actions/Orders/OrderConfirmationTexts.php`, after `sendWaitingNow()`:

```php
    /**
     * Rewrite the order's text if it's still waiting to go, so it gives what the order is now, like a night the
     * customer has just changed. A text that's sent, or being sent, is left alone.
     */
    public function rewriteWaiting(Order $order): void
    {
        $message = $order->confirmationText()->with(['orders.items.product', 'orders.drop'])->first();

        if (! $message instanceof SmsMessage || $message->status !== SmsStatus::Queued) {
            return;
        }

        $orders = array_values($message->orders->sortBy('id')->all());

        if ($orders === []) {
            return;
        }

        SmsMessage::query()
            ->whereKey($message->id)
            ->where('status', SmsStatus::Queued)
            ->update(['body' => OrderConfirmationText::for($orders)->text]);
    }
```

**Step 4: Write the owner's email**

Create `app/Mail/DeliveryNightChanged.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the farm a customer moved their delivery to another night from their order page, in case the run is planned.
 */
class DeliveryNightChanged extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * A failed send is tried again a few times, like the other order emails.
     */
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 600];

    use SerializesModels;

    /**
     * @param  string  $previousNight  as customers read it: "Saturday night, 21 November"
     */
    public function __construct(public Order $order, public string $previousNight)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->order->reference()} moved to {$this->order->deliveryNight()}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.orders.night-changed');
    }
}
```

Create `resources/views/mail/orders/night-changed.blade.php`:

```blade
<x-mail::message>
# {{ $order->reference() }} moved to {{ $order->deliveryNight() }}

**{{ \App\Support\MarkdownText::escape($order->customer_name) }}** moved their delivery from {{ $previousNight }} to **{{ $order->deliveryNight() }}** on their order page.

If you’ve already planned that run, check it still works.
</x-mail::message>
```

**Step 5: Write the action**

Create `app/Actions/Orders/ChangeDeliveryNight.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Exceptions\CannotChangeOrder;
use App\Mail\DeliveryNightChanged;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * Moves a delivery to another of its drop's nights, or to "either night", from the customer's order page.
 *
 * A change closes at 12pm (shop time) the day before the earlier of the two nights, so the owner can plan the run;
 * "either night" counts as the drop's first. Switching to pickup or changing the address moves money or the run, so
 * customers ask the owner for those. Each change emails the farm and rewrites the order text if it's still waiting.
 */
final readonly class ChangeDeliveryNight
{
    /**
     * What the order page sends for "either night suits me".
     */
    public const string EITHER = 'either';

    /**
     * Orders still to deliver.
     *
     * @var list<OrderStatus>
     */
    private const array CHANGEABLE = [OrderStatus::Paid, OrderStatus::Processing, OrderStatus::CashOwed];

    public function __construct(private OrderConfirmationTexts $texts) {}

    /**
     * The nights the order can move to now, as the page offers them: dates (Y-m-d), and "either" when the drop has more
     * than one night. Empty for a pickup, a delivered order, a one-night drop, or once it's too late.
     *
     * @return list<string>
     */
    public function choicesFor(Order $order): array
    {
        $nights = $order->drop->delivery_days;

        if ($order->delivery_method !== DeliveryMethod::Delivery || ! in_array($order->status, self::CHANGEABLE, true) || count($nights) < 2) {
            return [];
        }

        $current = self::nightOf($order);

        return array_values(array_filter(
            [...$nights, self::EITHER],
            fn (string $night): bool => $night !== $current && now()->lt(self::closesAt($nights, $current, $night)),
        ));
    }

    /**
     * @param  Order  $order  with its drop loaded
     * @param  string  $night  one of the drop's delivery days (Y-m-d), or "either"
     *
     * @throws CannotChangeOrder
     */
    public function __invoke(Order $order, string $night): Order
    {
        if ($order->delivery_method !== DeliveryMethod::Delivery) {
            throw new CannotChangeOrder('This order is a farm pickup, so there’s no delivery night to change.');
        }

        if ($night === self::nightOf($order)) {
            return $order;
        }

        if (! in_array($night, [...$order->drop->delivery_days, self::EITHER], true)) {
            throw new CannotChangeOrder('That isn’t one of this drop’s delivery nights. Choose another.');
        }

        if (! in_array($night, $this->choicesFor($order), true)) {
            throw new CannotChangeOrder('It’s too late to change your night here. Text us on '.config()->string('shop.phone.display').' and we’ll see what we can do.');
        }

        $previousNight = (string) $order->deliveryNight();
        $order->update(['delivery_day' => $night === self::EITHER ? null : $night]);

        $this->texts->rewriteWaiting($order);
        Mail::to(config()->string('shop.email'))->queue(new DeliveryNightChanged($order, $previousNight));

        return $order;
    }

    /**
     * The order's night as the page sends it.
     */
    private static function nightOf(Order $order): string
    {
        return $order->delivery_day?->toDateString() ?? self::EITHER;
    }

    /**
     * 12pm, shop time, the day before the earlier of two nights. "Either" counts as the drop's first night.
     *
     * @param  non-empty-list<string>  $nights  the drop's delivery days
     */
    private static function closesAt(array $nights, string $from, string $to): CarbonImmutable
    {
        $first = min($nights);
        $earlier = min($from === self::EITHER ? $first : $from, $to === self::EITHER ? $first : $to);

        return CarbonImmutable::parse($earlier, config()->string('shop.timezone'))->subDay()->setTime(12, 0);
    }
}
```

Broaden the docblock of `app/Exceptions/CannotChangeOrder.php` to:

```php
/**
 * A change to an order that the rules don't allow, like a free item on an abandoned checkout or a delivery night
 * after the cutoff, with the reason in words the person making it can act on.
 */
```

**Step 6: Write the request and controller**

Run: `php artisan make:request ChangeDeliveryNightRequest --no-interaction`

Replace its contents with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

class ChangeDeliveryNightRequest extends FormRequest
{
    /**
     * Keep the night picker's errors apart from the page's other forms.
     *
     * @var string
     */
    protected $errorBag = 'night';

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Which nights are allowed is ChangeDeliveryNight's call, so its reasons reach the customer.
            'night' => ['required', 'string', 'max:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['night.required' => 'Choose a night.'];
    }

    /**
     * Back to the delivery part of the order page.
     */
    protected function getRedirectUrl(): string
    {
        $order = $this->route('customerOrder');

        return $order instanceof Order ? $order->pageUrl().'#delivery' : parent::getRedirectUrl();
    }
}
```

Run: `php artisan make:controller Orders/ChangeDeliveryNightController --invokable --no-interaction`

Replace its contents with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\ChangeDeliveryNight;
use App\Exceptions\CannotChangeOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeDeliveryNightRequest;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;

/**
 * The night picker on a customer's order page.
 */
class ChangeDeliveryNightController extends Controller
{
    public function __invoke(ChangeDeliveryNightRequest $request, Order $customerOrder, ChangeDeliveryNight $changeNight): RedirectResponse
    {
        $back = redirect($customerOrder->pageUrl().'#delivery');

        try {
            $changeNight($customerOrder->load('drop'), $request->string('night')->value());
        } catch (CannotChangeOrder $exception) {
            return $back->withErrors(['night' => $exception->getMessage()], 'night');
        }

        return $back->with('order-page.night', "Done. We’ll deliver on {$customerOrder->deliveryNight()}.");
    }
}
```

**Step 7: Add the route**

In `routes/web.php`, add `use App\Http\Controllers\Orders\ChangeDeliveryNightController;` and, inside the
`orders/{customerOrder}` group after the `orders.show` route:

```php
    Route::post('/night', ChangeDeliveryNightController::class)->name('orders.night');
```

**Step 8: Show the picker**

Replace `app/Http/Controllers/Orders/ShowOrderController.php`'s `__invoke()` with:

```php
    public function __invoke(Order $customerOrder, ChangeDeliveryNight $nights): View
    {
        $customerOrder->load(['items.product', 'drop']);

        return view('pages.order-details', [
            'order' => $customerOrder,
            'alsoInText' => $this->alsoInText($customerOrder),
            'nightChoices' => $nights->choicesFor($customerOrder),
        ]);
    }
```

and add `use App\Actions\Orders\ChangeDeliveryNight;` to its imports.

Create `resources/views/pages/order-details/_change-night.blade.php`:

```blade
{{-- The delivery night picker. Expects $order (with its drop) and $nightChoices (see ChangeDeliveryNight::choicesFor()). --}}
@php
    use App\Actions\Orders\ChangeDeliveryNight;
    use Carbon\CarbonImmutable;

    /** @var \App\Models\Order $order */
    /** @var list<string> $nightChoices */
    $current = $order->delivery_day?->toDateString() ?? ChangeDeliveryNight::EITHER;
    $nights = array_values(array_filter(
        [...$order->drop->delivery_days, ChangeDeliveryNight::EITHER],
        fn (string $night): bool => $night === $current || in_array($night, $nightChoices, true),
    ));
    $label = fn (string $night): string => $night === ChangeDeliveryNight::EITHER
        ? 'Either night suits me'
        : CarbonImmutable::parse($night)->format('l').' night, '.CarbonImmutable::parse($night)->format('j F');
@endphp

<form method="post" action="{{ route('orders.night', ['customerOrder' => $order->access_token]) }}" class="mt-5 print:hidden">
    @csrf

    <fieldset>
        <legend class="mb-2 font-semibold text-forest">Need a different night?</legend>

        @foreach ($nights as $night)
            <label class="flex items-center gap-3 py-1">
                <input type="radio" name="night" value="{{ $night }}" @checked($night === $current) class="h-5 w-5 accent-forest">
                {{ $label($night) }}
            </label>
        @endforeach
    </fieldset>

    <p class="mt-2 text-sm text-gray-600">You can change it until 12pm the day before.</p>

    <x-button variant="outline" class="mt-4">Change night</x-button>
</form>
```

In `resources/views/pages/order-details/_fulfilment.blade.php`, update the header comment to mention `$nightChoices`, and
right after the delivery block's `@endif` that closes `@if ($address !== null)`, add:

```blade

        @if (session('order-page.night'))
            <p class="mt-4 border-l-4 border-sage bg-mint/10 p-4 text-forest" role="status">{{ session('order-page.night') }}</p>
        @endif

        @if ($errors->getBag('night')->has('night'))
            <p class="mt-4 border-l-4 border-red-700 bg-red-50 p-4 text-red-900" role="alert">{{ $errors->getBag('night')->first('night') }}</p>
        @endif

        @if ($nightChoices !== [])
            @include('pages.order-details._change-night')
        @endif
```

The message and error sit outside the form, so "too late" still shows after the picker has gone.

**Step 9: Run the tests**

Run: `php artisan test --compact tests/Feature/Orders`
Expected: PASS

**Step 10: Commit**

```bash
git add app/Actions/Orders app/Mail/DeliveryNightChanged.php resources/views/mail/orders/night-changed.blade.php app/Http/Requests/ChangeDeliveryNightRequest.php app/Http/Controllers/Orders resources/views/pages/order-details app/Exceptions/CannotChangeOrder.php routes/web.php tests/Feature/Orders/ChangeDeliveryNightTest.php
git commit -m "Let customers change their delivery night from their order page"
```

---

### Task 8: Email me my order

**Files:**
- Create: `app/Http/Controllers/Orders/EmailOrderController.php`
- Create: `resources/views/pages/order-details/_email.blade.php`
- Modify: `app/Models/Order.php` (`emailHint()`)
- Modify: `resources/views/pages/order-details.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Orders/EmailOrderTest.php`

**Step 1: Write the failing tests**

Run: `php artisan make:test --pest Orders/EmailOrderTest --no-interaction`

Replace its contents with:

```php
<?php

declare(strict_types=1);

use App\Mail\OrderConfirmation;
use Illuminate\Support\Facades\Mail;

it('emails the order to the address given at checkout', function (): void {
    Mail::fake();
    $order = samsWeekendOrder();

    $this->post(route('orders.email', ['customerOrder' => $order->access_token]))
        ->assertRedirect($order->pageUrl().'#email')
        ->assertSessionHas('order-page.email', 'Sent to s•••@example.com. It should arrive in a minute or two.');

    Mail::assertQueued(OrderConfirmation::class, fn (OrderConfirmation $mail): bool => $mail->hasTo('sam@example.com') && $mail->order->is($order));
});

it('sends it at most once every ten minutes', function (): void {
    Mail::fake();
    $order = samsWeekendOrder();
    $emailIt = fn () => $this->post(route('orders.email', ['customerOrder' => $order->access_token]));

    $emailIt();
    $emailIt()->assertSessionHas('order-page.email', 'We’ve just sent it to s•••@example.com. Give it a few minutes, and check your junk folder.');
    Mail::assertQueued(OrderConfirmation::class, 1);

    $this->travel(10)->minutes();
    $emailIt();
    Mail::assertQueued(OrderConfirmation::class, 2);
});

it('shows only a hint of the address on the page', function (): void {
    $this->get(samsWeekendOrder()->pageUrl())
        ->assertOk()
        ->assertSeeInOrder(['Email me my order', 's•••@example.com'])
        ->assertDontSee('sam@example.com');
});

it('has nothing to send for an order without an email', function (): void {
    Mail::fake();
    $order = samsWeekendOrder(['email' => null]);

    $this->get($order->pageUrl())->assertOk()->assertDontSee('Email me my order');
    $this->post(route('orders.email', ['customerOrder' => $order->access_token]))->assertNotFound();
    Mail::assertNothingQueued();
});
```

**Step 2: Run them to make sure they fail**

Run: `php artisan test --compact tests/Feature/Orders/EmailOrderTest.php`
Expected: FAIL (route `orders.email` not defined).

**Step 3: Add `emailHint()` to the order**

In `app/Models/Order.php`, after `firstName()`:

```php
    /**
     * The email address with most of its name hidden, for the order page: "s•••@gmail.com". Null without one.
     */
    public function emailHint(): ?string
    {
        if ($this->email === null || ! str_contains($this->email, '@')) {
            return null;
        }

        [$name, $domain] = explode('@', $this->email, 2);

        return mb_substr($name, 0, 1).'•••@'.$domain;
    }
```

**Step 4: Write the controller**

Run: `php artisan make:controller Orders/EmailOrderController --invokable --no-interaction`

Replace its contents with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Mail\OrderConfirmation;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Emails a customer their order again from its page. It only ever goes to the address they gave at checkout, and at
 * most once every ten minutes, so the page can't be used to fill someone's inbox.
 */
class EmailOrderController extends Controller
{
    public function __invoke(Order $customerOrder): RedirectResponse
    {
        $email = $customerOrder->email;

        abort_if($email === null, 404);

        $sent = RateLimiter::attempt("order-email:{$customerOrder->id}", 1, function () use ($customerOrder, $email): void {
            Mail::to($email)->queue(new OrderConfirmation($customerOrder));
        }, decaySeconds: 600);

        return redirect($customerOrder->pageUrl().'#email')->with('order-page.email', $sent !== false
            ? "Sent to {$customerOrder->emailHint()}. It should arrive in a minute or two."
            : "We’ve just sent it to {$customerOrder->emailHint()}. Give it a few minutes, and check your junk folder.");
    }
}
```

**Step 5: Add the route**

In `routes/web.php`, add `use App\Http\Controllers\Orders\EmailOrderController;` and, inside the
`orders/{customerOrder}` group:

```php
    Route::post('/email', EmailOrderController::class)->name('orders.email');
```

**Step 6: Add the section**

Create `resources/views/pages/order-details/_email.blade.php`:

```blade
{{-- Sends the order confirmation again, to the address on the order only. Expects $order. --}}
@if ($order->email !== null)
    <section id="email" aria-labelledby="email-heading" class="border border-forest/5 bg-white p-8 shadow-xl">
        <h2 id="email-heading" class="mb-3 font-display text-2xl font-semibold text-forest">Email me my order</h2>
        <p class="mb-5">We’ll send your order and receipt to {{ $order->emailHint() }}, the address you gave at checkout.</p>

        @if (session('order-page.email'))
            <p class="mb-5 border-l-4 border-sage bg-mint/10 p-4 text-forest" role="status">{{ session('order-page.email') }}</p>
        @endif

        <form method="post" action="{{ route('orders.email', ['customerOrder' => $order->access_token]) }}">
            @csrf
            <x-button variant="outline">
                <x-svg-icon name="mail" />
                Email it to me
            </x-button>
        </form>
    </section>
@endif
```

In `resources/views/pages/order-details.blade.php`, inside the `actions` slot, put the email section before the
questions:

```blade
                    @include('pages.order-details._email')
                    @include('pages.order-details._questions')
```

**Step 7: Run the tests**

Run: `php artisan test --compact tests/Feature/Orders`
Expected: PASS

**Step 8: Commit**

```bash
git add app/Http/Controllers/Orders/EmailOrderController.php resources/views/pages/order-details.blade.php resources/views/pages/order-details/_email.blade.php app/Models/Order.php routes/web.php tests/Feature/Orders/EmailOrderTest.php
git commit -m "Let customers email themselves their order from its page"
```

---

### Task 9: Feedback

**Files:**
- Create: `app/Models/OrderFeedback.php`, `database/migrations/<timestamp>_create_order_feedback_table.php`, `database/factories/OrderFeedbackFactory.php` (via artisan)
- Create: `app/Http/Requests/LeaveFeedbackRequest.php`
- Create: `app/Http/Controllers/Orders/LeaveFeedbackController.php`
- Create: `app/Mail/NewFeedbackNotification.php`
- Create: `resources/views/mail/orders/new-feedback.blade.php`
- Create: `resources/views/pages/order-details/_feedback.blade.php`
- Modify: `app/Models/Order.php` (`feedback()`)
- Modify: `app/Http/Controllers/Orders/ShowOrderController.php`
- Modify: `resources/views/pages/order-details.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Orders/OrderFeedbackTest.php`

**Step 1: Create the model, migration and factory**

Run: `php artisan make:model OrderFeedback --migration --factory --no-interaction`

Replace the migration (`database/migrations/<timestamp>_create_order_feedback_table.php`) with:

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
        Schema::create('order_feedback', function (Blueprint $table): void {
            $table->id();
            // One per order: leaving it again replaces it.
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE order_feedback ADD CONSTRAINT order_feedback_rating_is_one_to_five CHECK (rating BETWEEN 1 AND 5)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_feedback');
    }
};
```

Replace `app/Models/OrderFeedback.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OrderFeedbackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a customer thought of their order, left on its page: 1 to 5 stars and an optional comment. One per order;
 * leaving it again replaces it.
 *
 * @property int $id
 * @property int $order_id
 * @property int $rating
 * @property string|null $comment
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Order $order
 */
#[Fillable(['order_id', 'rating', 'comment'])]
class OrderFeedback extends Model
{
    /** @use HasFactory<OrderFeedbackFactory> */
    use HasFactory;

    protected $table = 'order_feedback';

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The rating as stars: "★★★★☆".
     */
    public function stars(): string
    {
        return str_repeat('★', $this->rating).str_repeat('☆', 5 - $this->rating);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }
}
```

Replace `database/factories/OrderFeedbackFactory.php` with:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderFeedback;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderFeedback>
 */
class OrderFeedbackFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'rating' => fake()->numberBetween(1, 5),
            'comment' => fake()->optional()->sentence(),
        ];
    }
}
```

In `app/Models/Order.php`, add `use Illuminate\Database\Eloquent\Relations\HasOne;`, add
`@property-read OrderFeedback|null $feedback` to the docblock, and after `items()`:

```php
    /**
     * What the customer thought of it, left on its page.
     *
     * @return HasOne<OrderFeedback, $this>
     */
    public function feedback(): HasOne
    {
        return $this->hasOne(OrderFeedback::class);
    }
```

**Step 2: Write the failing tests**

Run: `php artisan make:test --pest Orders/OrderFeedbackTest --no-interaction`

Replace its contents with:

```php
<?php

declare(strict_types=1);

use App\Mail\NewFeedbackNotification;
use App\Models\Order;
use App\Models\OrderFeedback;
use Illuminate\Support\Facades\Mail;

/**
 * Leave feedback from the order's page.
 *
 * @param  array<string, mixed>  $answers
 */
function leaveFeedback(Order $order, array $answers): \Illuminate\Testing\TestResponse
{
    return test()->post(route('orders.feedback', ['customerOrder' => $order->access_token]), $answers);
}

it('saves the customer’s stars and comment, and tells the farm', function (): void {
    Mail::fake();
    $order = samsWeekendOrder();

    leaveFeedback($order, ['rating' => 5, 'comment' => 'Best mince we’ve had.'])
        ->assertRedirect($order->pageUrl().'#feedback')
        ->assertSessionHas('order-page.feedback', 'Thank you! We read every one.');

    expect($order->feedback()->sole())->rating->toBe(5)->comment->toBe('Best mince we’ve had.');
    Mail::assertQueued(NewFeedbackNotification::class, fn (NewFeedbackNotification $mail): bool => $mail->hasTo(config()->string('shop.email')) && ! $mail->edited);
});

it('replaces their feedback when they leave it again', function (): void {
    Mail::fake();
    $order = samsWeekendOrder();
    OrderFeedback::factory()->for($order)->create(['rating' => 2, 'comment' => 'Running late.']);

    leaveFeedback($order, ['rating' => 4, 'comment' => '']);

    expect($order->feedback()->sole())->rating->toBe(4)->comment->toBeNull();
    Mail::assertQueued(NewFeedbackNotification::class, fn (NewFeedbackNotification $mail): bool => $mail->edited);
});

it('shows the feedback they left, ready to change', function (): void {
    $order = samsWeekendOrder();
    OrderFeedback::factory()->for($order)->create(['rating' => 4, 'comment' => 'Best mince we’ve had.']);

    $this->get($order->pageUrl())->assertOk()->assertSeeInOrder(['How did we do?', 'Best mince we’ve had.', 'Update feedback']);
});

it('says what’s wrong with feedback it can’t take', function (array $answers, string $field, string $message): void {
    Mail::fake();
    $order = samsWeekendOrder();

    leaveFeedback($order, $answers)
        ->assertRedirect($order->pageUrl().'#feedback')
        ->assertSessionHasErrorsIn('feedback', [$field => $message]);

    expect($order->feedback()->exists())->toBeFalse();
    Mail::assertNothingQueued();
})->with([
    'no stars' => [['comment' => 'Lovely'], 'rating', 'Choose from 1 to 5 stars.'],
    'six stars' => [['rating' => 6], 'rating', 'Choose from 1 to 5 stars.'],
    'a comment that’s too long' => [['rating' => 5, 'comment' => str_repeat('a', 2001)], 'comment', 'Please keep it under 2,000 characters.'],
]);

it('tells the farm the stars, who left them and what they said', function (): void {
    $feedback = OrderFeedback::factory()->for(samsWeekendOrder())->create(['rating' => 4, 'comment' => 'Best mince ever']);

    // The stars are checked in the subject: the email's CSS inliner may write them as entities in the HTML.
    (new NewFeedbackNotification($feedback, edited: false))
        ->assertHasSubject("★★★★☆ from Sam Buyer for {$feedback->order->reference()}")
        ->assertSeeInOrderInHtml([$feedback->order->reference(), 'Sam Buyer', 'left feedback', 'Best mince ever']);
});

it('keeps what the customer typed as plain text in the farm’s email', function (): void {
    $order = samsWeekendOrder(['customer_name' => '[Win](https://evil.test) Sam']);
    $feedback = OrderFeedback::factory()->for($order)->create(['comment' => '<script>alert(1)</script> [link](https://evil.test)']);

    $html = (new NewFeedbackNotification($feedback, edited: false))->render();

    expect($html)
        ->toContain('&lt;script&gt;')
        ->not->toContain('<script>alert(1)</script>')
        ->not->toContain('href="https://evil.test"');
});
```

Check `leaveFeedback` is a free name: `grep -rn "function leaveFeedback" tests`.

**Step 3: Run them to make sure they fail**

Run: `php artisan migrate --no-interaction && php artisan test --compact tests/Feature/Orders/OrderFeedbackTest.php`
Expected: FAIL (route `orders.feedback` and the mail don't exist).

**Step 4: Write the farm's email**

Create `app/Mail/NewFeedbackNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\OrderFeedback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the farm what a customer thought of their order, each time they leave or change it.
 */
class NewFeedbackNotification extends Mailable implements ShouldQueue
{
    use Queueable;

    /**
     * A failed send is tried again a few times, like the other order emails.
     */
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 600];

    use SerializesModels;

    /**
     * @param  bool  $edited  they changed feedback they'd left before
     */
    public function __construct(public OrderFeedback $feedback, public bool $edited)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $order = $this->feedback->loadMissing('order')->order;

        return new Envelope(subject: "{$this->feedback->stars()} from {$order->customer_name} for {$order->reference()}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.orders.new-feedback', with: ['order' => $this->feedback->loadMissing('order')->order]);
    }
}
```

Create `resources/views/mail/orders/new-feedback.blade.php`:

```blade
<x-mail::message>
# {{ $feedback->stars() }} for {{ $order->reference() }}

**{{ \App\Support\MarkdownText::escape($order->customer_name) }}** {{ $edited ? 'changed their feedback' : 'left feedback' }} on their order page.

@if ($feedback->comment !== null)
{{ \App\Support\MarkdownText::escape($feedback->comment) }}
@else
No comment, just the stars.
@endif
</x-mail::message>
```

**Step 5: Write the request and controller**

Run: `php artisan make:request LeaveFeedbackRequest --no-interaction`

Replace its contents with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

class LeaveFeedbackRequest extends FormRequest
{
    /**
     * Keep the feedback form's errors apart from the page's other forms.
     *
     * @var string
     */
    protected $errorBag = 'feedback';

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rating.required' => 'Choose from 1 to 5 stars.',
            'rating.integer' => 'Choose from 1 to 5 stars.',
            'rating.between' => 'Choose from 1 to 5 stars.',
            'comment.max' => 'Please keep it under 2,000 characters.',
        ];
    }

    /**
     * Back to the feedback form at the bottom of the order page.
     */
    protected function getRedirectUrl(): string
    {
        $order = $this->route('customerOrder');

        return $order instanceof Order ? $order->pageUrl().'#feedback' : parent::getRedirectUrl();
    }
}
```

Run: `php artisan make:controller Orders/LeaveFeedbackController --invokable --no-interaction`

Replace its contents with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeaveFeedbackRequest;
use App\Mail\NewFeedbackNotification;
use App\Models\Order;
use App\Models\OrderFeedback;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;

/**
 * The feedback form at the bottom of a customer's order page. Leaving it again replaces it, and each time tells the farm.
 */
class LeaveFeedbackController extends Controller
{
    public function __invoke(LeaveFeedbackRequest $request, Order $customerOrder): RedirectResponse
    {
        $comment = $request->validated('comment');

        $feedback = OrderFeedback::query()->updateOrCreate(
            ['order_id' => $customerOrder->id],
            ['rating' => $request->integer('rating'), 'comment' => is_string($comment) ? $comment : null],
        );

        Mail::to(config()->string('shop.email'))->queue(new NewFeedbackNotification($feedback, edited: ! $feedback->wasRecentlyCreated));

        return redirect($customerOrder->pageUrl().'#feedback')->with('order-page.feedback', 'Thank you! We read every one.');
    }
}
```

**Step 6: Add the route**

In `routes/web.php`, add `use App\Http\Controllers\Orders\LeaveFeedbackController;` and, inside the
`orders/{customerOrder}` group:

```php
    Route::post('/feedback', LeaveFeedbackController::class)->name('orders.feedback');
```

**Step 7: Add the form**

In `app/Http/Controllers/Orders/ShowOrderController.php`, load the feedback too:

```php
        $customerOrder->load(['items.product', 'drop', 'feedback']);
```

Create `resources/views/pages/order-details/_feedback.blade.php`:

```blade
{{-- Stars and a comment, at the bottom of the page. Expects $order with its feedback. --}}
@php
    $errorBag = $errors->getBag('feedback');
    $rating = (int) old('rating', $order->feedback?->rating);
    $comment = old('comment', $order->feedback?->comment);
@endphp

<section id="feedback" aria-labelledby="feedback-heading" class="border border-forest/5 bg-white p-8 shadow-xl">
    <h2 id="feedback-heading" class="mb-2 font-display text-2xl font-semibold text-forest">How did we do?</h2>
    <p class="mb-5">We’re a small family farm, so every bit of feedback helps. Only we see it.</p>

    @if (session('order-page.feedback'))
        <p class="mb-5 border-l-4 border-sage bg-mint/10 p-4 text-forest" role="status">{{ session('order-page.feedback') }}</p>
    @endif

    <form method="post" action="{{ route('orders.feedback', ['customerOrder' => $order->access_token]) }}" class="space-y-5">
        @csrf

        <fieldset @if ($errorBag->has('rating')) aria-describedby="feedback-rating-error" @endif>
            <legend class="mb-2 font-semibold text-forest">Your rating</legend>

            <div class="flex flex-wrap gap-2">
                @foreach (range(1, 5) as $stars)
                    <label class="cursor-pointer">
                        <input type="radio" name="rating" value="{{ $stars }}" @checked($rating === $stars) class="peer sr-only">
                        <span class="inline-flex min-h-12 items-center gap-1 border border-forest/20 px-4 text-forest peer-checked:bg-forest peer-checked:text-cream peer-focus-visible:ring-2 peer-focus-visible:ring-sage">
                            {{ $stars }}
                            <x-svg-icon name="star" />
                            <span class="sr-only">{{ $stars === 1 ? 'star' : 'stars' }}</span>
                        </span>
                    </label>
                @endforeach
            </div>

            @if ($errorBag->has('rating'))
                <p id="feedback-rating-error" class="mt-2 text-red-800">{{ $errorBag->first('rating') }}</p>
            @endif
        </fieldset>

        <div>
            <label for="feedback-comment" class="mb-2 block font-semibold text-forest">Anything you’d like to tell us? <span class="font-normal text-gray-600">(optional)</span></label>
            <textarea id="feedback-comment" name="comment" rows="4" maxlength="2000" class="w-full border border-forest/20 bg-white p-3 focus:border-sage focus:outline-none" @if ($errorBag->has('comment')) aria-describedby="feedback-comment-error" aria-invalid="true" @endif>{{ $comment }}</textarea>

            @if ($errorBag->has('comment'))
                <p id="feedback-comment-error" class="mt-2 text-red-800">{{ $errorBag->first('comment') }}</p>
            @endif
        </div>

        <x-button>{{ $order->feedback === null ? 'Send feedback' : 'Update feedback' }}</x-button>
    </form>
</section>
```

In `resources/views/pages/order-details.blade.php`, inside the `actions` slot, after the questions:

```blade
                    @include('pages.order-details._feedback')
```

**Step 8: Run the tests**

Run: `php artisan test --compact tests/Feature/Orders`
Expected: PASS

**Step 9: Commit**

```bash
git add app/Models/OrderFeedback.php app/Models/Order.php database/migrations/*_create_order_feedback_table.php database/factories/OrderFeedbackFactory.php app/Http/Requests/LeaveFeedbackRequest.php app/Http/Controllers/Orders app/Mail/NewFeedbackNotification.php resources/views/mail/orders/new-feedback.blade.php resources/views/pages/order-details.blade.php resources/views/pages/order-details/_feedback.blade.php routes/web.php tests/Feature/Orders/OrderFeedbackTest.php
git commit -m "Take feedback at the bottom of the order page"
```

---

### Task 10: Feedback and the page link in the admin

**Files:**
- Create: `app/Filament/Resources/OrderFeedback/OrderFeedbackResource.php`
- Create: `app/Filament/Resources/OrderFeedback/Pages/ListOrderFeedback.php`
- Create: `app/Filament/Resources/OrderFeedback/Tables/OrderFeedbackTable.php`
- Modify: `app/Filament/Resources/Orders/OrderResource.php` (eager load)
- Modify: `app/Filament/Resources/Orders/Schemas/OrderInfolist.php`
- Test: `tests/Feature/Admin/OrderFeedbackAdminTest.php`

**Step 1: Write the failing tests**

Run: `php artisan make:test --pest Admin/OrderFeedbackAdminTest --no-interaction`

Replace its contents with:

```php
<?php

declare(strict_types=1);

use App\Filament\Resources\OrderFeedback\Pages\ListOrderFeedback;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\OrderFeedback;
use App\Models\User;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));
});

it('lists what customers thought, with who left it and their order', function (): void {
    $order = Order::factory()->create(['customer_name' => 'Sam Buyer']);
    $feedback = OrderFeedback::factory()->for($order)->create(['rating' => 4, 'comment' => 'Best mince we’ve had.']);

    livewire(ListOrderFeedback::class)
        ->assertCanSeeTableRecords([$feedback])
        ->assertSee('★★★★☆')
        ->assertSee('Best mince we’ve had.')
        ->assertSee('Sam Buyer')
        ->assertSee($order->reference());
});

it('shows the customer’s page link and their feedback on the order', function (): void {
    $order = Order::factory()->create();
    OrderFeedback::factory()->for($order)->create(['rating' => 2, 'comment' => 'Too much fat on the steaks.']);

    livewire(ViewOrder::class, ['record' => $order->id])
        ->assertSee($order->access_token)
        ->assertSeeTextInOrder(['Feedback', '★★☆☆☆', 'Too much fat on the steaks.']);
});
```

**Step 2: Run them to make sure they fail**

Run: `php artisan test --compact tests/Feature/Admin/OrderFeedbackAdminTest.php`
Expected: FAIL

**Step 3: Show the link and feedback on the order**

In `app/Filament/Resources/Orders/OrderResource.php`, `getRecordRouteBindingEloquentQuery()` loads the feedback too:

```php
        return parent::getRecordRouteBindingEloquentQuery()->with(['drop', 'items.product', 'feedback']);
```

In `app/Filament/Resources/Orders/Schemas/OrderInfolist.php`, add as the last entry of the `Order` section's schema:

```php
                        TextEntry::make('page')
                            ->label('Customer page')
                            ->state(fn (Order $record): string => $record->pageUrl())
                            ->url(fn (Order $record): string => $record->pageUrl(), shouldOpenInNewTab: true)
                            ->copyable()
                            ->columnSpanFull(),
```

and, after the `Items` section, a new section:

```php
                Section::make('Feedback')
                    ->columnSpanFull()
                    ->visible(fn (Order $record): bool => $record->feedback !== null)
                    ->schema([
                        TextEntry::make('feedback_stars')->label('Rating')->state(fn (Order $record): ?string => $record->feedback?->stars()),
                        TextEntry::make('feedback.comment')->label('Comment')->placeholder('No comment'),
                        TextEntry::make('feedback.updated_at')->label('Left')->dateTime('D j M Y, g:ia', $timezone),
                    ]),
```

**Step 4: Write the feedback list**

Create `app/Filament/Resources/OrderFeedback/OrderFeedbackResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderFeedback;

use App\Filament\Resources\OrderFeedback\Pages\ListOrderFeedback;
use App\Filament\Resources\OrderFeedback\Tables\OrderFeedbackTable;
use App\Models\OrderFeedback;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * What customers thought of their orders, left on their order pages. Read only: it's theirs.
 */
class OrderFeedbackResource extends Resource
{
    protected static ?string $model = OrderFeedback::class;

    protected static ?string $modelLabel = 'feedback';

    protected static ?string $pluralModelLabel = 'feedback';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static ?int $navigationSort = 0;

    public static function table(Table $table): Table
    {
        return OrderFeedbackTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrderFeedback::route('/'),
        ];
    }
}
```

Create `app/Filament/Resources/OrderFeedback/Pages/ListOrderFeedback.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderFeedback\Pages;

use App\Filament\Resources\OrderFeedback\OrderFeedbackResource;
use Filament\Resources\Pages\ListRecords;

class ListOrderFeedback extends ListRecords
{
    protected static string $resource = OrderFeedbackResource::class;
}
```

Create `app/Filament/Resources/OrderFeedback/Tables/OrderFeedbackTable.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderFeedback\Tables;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\OrderFeedback;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrderFeedbackTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('order'))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('updated_at')->label('Left')->dateTime('D j M, g:ia', config()->string('shop.timezone'))->sortable(),
                TextColumn::make('rating')->formatStateUsing(fn (OrderFeedback $record): string => $record->stars())->sortable(),
                TextColumn::make('comment')->wrap()->placeholder('No comment'),
                TextColumn::make('order.customer_name')->label('Customer'),
                TextColumn::make('reference')
                    ->label('Order')
                    ->state(fn (OrderFeedback $record): string => $record->order->reference())
                    ->url(fn (OrderFeedback $record): string => OrderResource::getUrl('view', ['record' => $record->order])),
            ]);
    }
}
```

**Step 5: Run the tests**

Run: `php artisan test --compact tests/Feature/Admin/OrderFeedbackAdminTest.php tests/Feature/Admin/OrderResourceTest.php tests/Feature/Admin/FreeItemsAdminTest.php`
Expected: PASS

**Step 6: Commit**

```bash
git add app/Filament/Resources/OrderFeedback app/Filament/Resources/Orders/OrderResource.php app/Filament/Resources/Orders/Schemas/OrderInfolist.php tests/Feature/Admin/OrderFeedbackAdminTest.php
git commit -m "Show feedback and the customer's page link in the admin"
```

---

### Task 11: Feedback and the page link for Claude

**Files:**
- Modify: `app/Mcp/Support/Presenter.php`
- Modify: `app/Mcp/Tools/Orders/GetOrderTool.php`
- Test: `tests/Feature/Mcp/ReadToolsTest.php`

**Step 1: Write the failing test**

In `tests/Feature/Mcp/ReadToolsTest.php`, add `use App\Models\OrderFeedback;` to the imports, and inside the
`describe('orders', ...)` block that holds the `GetOrderTool` test (around line 78), add:

```php
    it('gives an order’s page link and the feedback left on it', function (): void {
        $order = Order::factory()->create();
        OrderFeedback::factory()->for($order)->create(['rating' => 4, 'comment' => 'Great mince']);

        $this->server->tool(GetOrderTool::class, ['order' => $order->reference()])
            ->assertOk()
            ->assertSee($order->access_token)
            ->assertSee('Great mince');
    });
```

**Step 2: Run it to make sure it fails**

Run: `php artisan test --compact tests/Feature/Mcp/ReadToolsTest.php --filter="page link"`
Expected: FAIL

**Step 3: Add them to the detailed order**

In `app/Mcp/Support/Presenter.php`, inside `if ($detailed) { ... }` in `order()`, before `$presented += [`:

```php
            $feedback = $order->loadMissing('feedback')->feedback;
```

and add these keys to the `$presented += [...]` array, after `'stripe_payment_intent_id'`:

```php
                'page_url' => $order->pageUrl(),
                'feedback' => $feedback === null ? null : [
                    'rating' => $feedback->rating,
                    'comment' => $feedback->comment,
                    'left_at' => self::time($feedback->updated_at),
                ],
```

In `app/Mcp/Tools/Orders/GetOrderTool.php`, eager load it with the rest (`->with(['items.product', 'drop', 'feedback'])`)
and update the description:

```php
#[Description('One order by its reference (FL-XXXXXX), public id or numeric id, with the customer, delivery details, items, the customer’s order page link, their feedback and Stripe ids.')]
```

**Step 4: Run the MCP tests**

Run: `php artisan test --compact tests/Feature/Mcp`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Mcp/Support/Presenter.php app/Mcp/Tools/Orders/GetOrderTool.php tests/Feature/Mcp/ReadToolsTest.php
git commit -m "Give Claude an order's page link and feedback"
```

---

### Task 12: Check it all, then set up the domain

**Step 1: Format, analyse and run the affected tests**

Run: `vendor/bin/pint --dirty --format agent`
Run: `composer analyse`
Run: `php artisan test --compact tests/Feature/Orders tests/Feature/Sms tests/Feature/Checkout tests/Feature/Admin tests/Feature/Mcp tests/Feature/Http tests/Feature/LayoutTest.php`
Expected: all clean and passing. Fix anything PHPStan raises (likely spots: `min()` on `delivery_days`, `$request->validated()` types).
Commit any formatting or analysis fixes: `git commit -am "Tidy the order page"`.

**Step 2: Look at it in the browser**

Run `npm run build` (new Tailwind classes such as `print:hidden` and `peer-checked:*`), open a local order's page
(`php artisan tinker --execute 'echo App\Models\Order::query()->whereIn("status", ["paid", "fulfilled"])->latest("id")->first()?->pageUrl();'`),
and check on a phone-width window: the note, delivery details, night picker, receipt, print preview (only the letter
prints), the email button, the questions card and the feedback form. Send feedback and change the night once each.

**Step 3: Ask the owner to run the full suite**

Ask them to run `php artisan test --compact` (or `composer ci`).

**Step 4: Set up the domain (outside the code, owner)**

Pushes to `main` reach customers, so ship in this order:

1. Deploy the code with `SHOP_SHORT_DOMAIN` unset. Texts carry the full `fergusonlivestock.com.au/orders/…` link until
   the domain is ready, so nothing breaks.
2. Register `fergs.au` with an auDA-accredited registrar (needs the ABN). Consider `fergusonlivestock.au` too, so nobody
   can use it for phishing texts.
3. In Laravel Cloud, add `fergs.au` as a custom domain on the production environment and create the DNS records it
   shows. The `deploying-to-cloud` skill can help.
4. Check `https://fergs.au/` redirects to the home page and `https://fergs.au/<a real order's token>` opens its page.
5. Only then set `SHOP_SHORT_DOMAIN=fergs.au` on production and redeploy, so the short-domain routes are registered
   (routes are cached at deploy).
6. Preview the next drop's texts (`prepare-order-texts-tool`) to see the short link in place.
