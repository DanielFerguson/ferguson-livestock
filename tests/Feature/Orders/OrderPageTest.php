<?php

declare(strict_types=1);

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SmsStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SmsMessage;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

it('gives each order its own unguessable secret for its page link', function (): void {
    [$first, $second] = Order::factory()->count(2)->create()->all();

    expect($first->access_token)->toMatch('/^[A-Za-z0-9]{16}$/')
        ->and($second->access_token)->toMatch('/^[A-Za-z0-9]{16}$/')
        ->and($first->access_token)->not->toBe($second->access_token);
});

it('gives an order saved without a secret one from the database, as the previous release saves them during a deploy', function (): void {
    DB::table('orders')->insert([...Arr::except(Order::factory()->make()->getAttributes(), 'access_token'), 'public_id' => (string) Str::ulid()]);

    expect(Order::query()->sole()->access_token)->toMatch('/^[A-Za-z0-9]{16}$/');
});

it('shows the order to whoever has its link, thanking them by name', function (): void {
    $order = samsWeekendOrder();

    $this->get($order->pageUrl())
        ->assertOk()
        ->assertSeeInOrder(['Thanks, Sam!', 'We’re getting your order ready', 'Thank you for supporting our small family farm']);
});

it('thanks them in words that fit where the order is up to', function (Order $order, string $subheading): void {
    $this->get($order->pageUrl())->assertOk()->assertSee($subheading);
})->with([
    'payment clearing' => [fn (): Order => samsWeekendOrder(['status' => OrderStatus::Processing]), 'Your payment is on its way'],
    'delivered' => [fn (): Order => samsWeekendOrder(['status' => OrderStatus::Fulfilled]), 'Your order has been delivered'],
    'collected' => [fn (): Order => samsWeekendOrder(['status' => OrderStatus::Fulfilled, 'delivery_method' => DeliveryMethod::Pickup, 'delivery_day' => null, 'shipping_address' => null]), 'You’ve collected your order'],
    'refunded in full' => [fn (): Order => samsWeekendOrder(['amount_refunded' => 17500]), 'Your order has been refunded'],
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

it('turns away too many tries without handing the page’s address to Meta’s pixel', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    enableMetaAds();
    $order = samsWeekendOrder();

    foreach (range(1, 30) as $try) {
        $this->get($order->pageUrl())->assertOk();
    }

    $this->get($order->pageUrl())
        ->assertTooManyRequests()
        ->assertDontSee('fbq(', false)
        ->assertSee('<meta name="referrer" content="strict-origin">', false);
});

it('is a plain 404 for a wrong link', function (): void {
    $this->get('/orders/'.str_repeat('a', Order::ACCESS_TOKEN_LENGTH))->assertNotFound();
});

it('is a 404, without looking anything up, for a link that isn’t the shape of one', function (string $token): void {
    DB::enableQueryLog();

    $this->get("/orders/{$token}")->assertNotFound();
    $this->post("/orders/{$token}/night", ['night' => '2026-11-22'])->assertNotFound();

    expect(DB::getQueryLog())->toBeEmpty();
})->with([
    'too long' => [str_repeat('a', Order::ACCESS_TOKEN_LENGTH + 1)],
    'not just letters and numbers' => [str_repeat('a', Order::ACCESS_TOKEN_LENGTH - 1).'-'],
]);

it('is a 404 for a checkout that never became an order', function (OrderStatus $status): void {
    $order = Order::factory()->pending()->create(['status' => $status]);

    $this->get($order->pageUrl())->assertNotFound();
})->with([OrderStatus::Pending, OrderStatus::Expired, OrderStatus::Failed]);

describe('short links', function (): void {
    it('sends a link on the short domain to the order page', function (string $trailingSlash): void {
        $order = samsWeekendOrder();

        $this->get("https://fergs.au/{$order->access_token}{$trailingSlash}")
            ->assertRedirect("https://fergusonlivestock.com.au/orders/{$order->access_token}");
    })->with(['as texted' => '', 'with a trailing slash' => '/']);

    it('sends anything else on the short domain to the main site', function (string $path): void {
        $this->get("https://fergs.au{$path}")->assertRedirect('https://fergusonlivestock.com.au');
    })->with([
        'the bare domain' => '/',
        'a page' => '/order',
        'a too-short link' => '/abc123',
        'the admin' => '/admin',
        'the health check' => '/up',
    ]);

    it('sends a link on whatever the capitals in the setting', function (): void {
        config(['shop.short_domain' => 'Fergs.au']);
        $order = samsWeekendOrder();

        $this->get("https://fergs.au/{$order->access_token}")
            ->assertRedirect("https://fergusonlivestock.com.au/orders/{$order->access_token}");
    });

    it('reads the short domain however it’s typed in the setting', function (string $setting, ?string $shortDomain): void {
        $server = $_SERVER;
        $_SERVER['SHOP_SHORT_DOMAIN'] = $setting;

        try {
            /** @var array{short_domain: string|null} $config */
            $config = require config_path('shop.php');
        } finally {
            $_SERVER = $server;
        }

        expect($config['short_domain'])->toBe($shortDomain);
    })->with([
        'with capitals' => ['Fergs.au', 'fergs.au'],
        'as a link' => ['https://fergs.au/', 'fergs.au'],
        'with spaces' => [' fergs.au ', 'fergs.au'],
        'left empty' => ['', null],
    ]);

    it('leaves the main site alone when the setting names it by mistake', function (): void {
        config(['shop.short_domain' => 'fergusonlivestock.com.au']);

        $this->get('https://fergusonlivestock.com.au/')->assertOk();
    });

    it('sends a link on without a doubled slash when the main site’s address ends in one', function (): void {
        config(['shop.url' => 'https://fergusonlivestock.com.au/']);
        $order = samsWeekendOrder();

        $this->get("https://fergs.au/{$order->access_token}")
            ->assertRedirect("https://fergusonlivestock.com.au/orders/{$order->access_token}");
    });

    it('sends a form posted to the short domain to the main site too', function (): void {
        $this->post('https://fergs.au/waitlist', ['first_name' => 'Sam'])->assertRedirect('https://fergusonlivestock.com.au');
    });

    it('gives texts the short link', function (): void {
        $order = samsWeekendOrder();

        expect($order->shortLink())->toBe("https://fergs.au/{$order->access_token}");
    });

    it('gives texts the page’s full address when there’s no short domain', function (): void {
        config(['shop.short_domain' => null]);
        URL::useOrigin('https://fergusonlivestock.com.au');
        URL::forceHttps();
        $order = samsWeekendOrder();

        expect($order->shortLink())->toBe("https://fergusonlivestock.com.au/orders/{$order->access_token}");
    });
});

it('tells a delivery customer their night and address', function (): void {
    $this->get(samsWeekendOrder()->pageUrl())
        ->assertOk()
        ->assertSeeInOrder(['Delivery', 'Saturday night, 21 November', '1 Main St', 'Ballarat 3350']);
});

it('tells a pickup customer where and when to collect, with the farm’s map', function (): void {
    $order = samsWeekendOrder(['delivery_method' => DeliveryMethod::Pickup, 'delivery_day' => null, 'shipping_address' => null]);

    $this->get($order->pageUrl())
        ->assertOk()
        ->assertSeeInOrder(['Farm pickup', '306 Snake Valley-Chepstowe Rd, Snake Valley, Saturday or Sunday, 10am to 4pm', 'Come up the driveway and park anywhere near the house', 'Open in Google Maps'])
        ->assertSee(route('farm'));
});

it('tells a customer whose order was refunded in full that there’s nothing to deliver or collect', function (Order $order, string $nothingToDo): void {
    $this->get($order->pageUrl())
        ->assertOk()
        ->assertSee($nothingToDo)
        ->assertDontSee('We’ll bring it to you')
        ->assertDontSee('Collect it from');
})->with([
    'a delivery' => [fn (): Order => samsWeekendOrder(['amount_refunded' => 17500]), 'This order has been refunded, so there’s nothing to deliver.'],
    'a pickup' => [fn (): Order => samsWeekendOrder(['amount_refunded' => 17500, 'delivery_method' => DeliveryMethod::Pickup, 'delivery_day' => null, 'shipping_address' => null]), 'This order has been refunded, so there’s nothing to collect.'],
]);

it('gives a receipt, with what was given free and anything refunded', function (): void {
    $order = samsWeekendOrder(['amount_refunded' => 1500]);
    OrderItem::factory()->for($order)->for(Product::factory()->create(['name' => '500g Beef Mince']))->free()->create(['quantity' => 2]);

    $this->get($order->pageUrl())
        ->assertOk()
        ->assertSeeInOrder(['Receipt', $order->reference(), '5kg Beef Box', '$160', 'Delivery', '$15', '2 × 500g Beef Mince', 'Free', 'Total', '$175', 'Refunded', '−$15']);
});

it('says on the receipt how the order was paid', function (Order $order, string $payment): void {
    $this->get($order->pageUrl())->assertOk()->assertSeeInOrder(['Receipt', $payment, 'Total']);
})->with([
    'online' => [fn (): Order => samsWeekendOrder(), 'Paid online'],
    'by bank, still clearing' => [fn (): Order => samsWeekendOrder(['status' => OrderStatus::Processing]), 'Bank payment, clearing'],
    'cash still owed, with no email or payment yet' => [fn (): Order => samsWeekendOrder(['status' => OrderStatus::CashOwed, 'payment_method' => PaymentMethod::Cash, 'email' => null, 'paid_at' => null]), 'Cash, still to pay'],
    'cash received' => [fn (): Order => samsWeekendOrder(['payment_method' => PaymentMethod::Cash, 'email' => null]), 'Paid in cash'],
]);

it('prints a plain receipt that says whether and when it was paid', function (Order $order, string $status, string $date, string $method, bool $isPaid): void {
    $response = $this->get($order->pageUrl())
        ->assertOk()
        ->assertSeeInOrder(['Receipt from Ferguson Livestock', '$175', $status, $date, $method, 'Total']);

    if ($isPaid) {
        $response->assertSee('Amount paid');
    } else {
        $response->assertDontSee('Amount paid');
    }
})->with([
    'paid online' => [fn (): Order => samsWeekendOrder(['paid_at' => '2026-11-14 01:00:00']), 'Paid 14 November 2026', 'Date paid', 'Online', true],
    'cash still owed' => [fn (): Order => samsWeekendOrder(['status' => OrderStatus::CashOwed, 'payment_method' => PaymentMethod::Cash, 'email' => null, 'paid_at' => null]), 'Cash, still to pay', 'Date ordered', 'Cash', false],
    'refunded in full' => [fn (): Order => samsWeekendOrder(['paid_at' => '2026-11-14 01:00:00', 'amount_refunded' => 17500, 'refunded_at' => '2026-11-16 01:00:00']), 'Refunded 16 November 2026', 'Date paid', 'Online', true],
]);

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

it('names the customer’s other orders that the same text covered, without linking their pages', function (): void {
    $text = SmsMessage::factory()->orderConfirmation(SmsStatus::Sent)->create();
    $order = samsWeekendOrder(['confirmation_sms_message_id' => $text->id]);
    $sameText = Order::factory()->for($order->drop)->create(['confirmation_sms_message_id' => $text->id, 'email' => 'SAM@example.com']);
    $otherText = Order::factory()->for($order->drop)->create(['confirmation_sms_message_id' => SmsMessage::factory()->orderConfirmation()->create()->id]);

    $this->get($order->pageUrl())
        ->assertOk()
        ->assertSeeInOrder(['Your other orders', $sameText->reference(), 'linked from its confirmation email'])
        ->assertDontSee($sameText->access_token)
        ->assertDontSee($otherText->reference());
});

it('leaves out an order in the same text that someone else placed with the customer’s number', function (): void {
    $text = SmsMessage::factory()->orderConfirmation(SmsStatus::Sent)->create();
    $order = samsWeekendOrder(['confirmation_sms_message_id' => $text->id]);
    $stranger = Order::factory()->for($order->drop)->create(['confirmation_sms_message_id' => $text->id, 'email' => 'stranger@example.com']);

    $this->get($order->pageUrl())
        ->assertOk()
        ->assertDontSee('Your other orders')
        ->assertDontSee($stranger->access_token);
});

it('lets the customer text or call Daniel about their order', function (): void {
    $order = samsWeekendOrder();

    $this->get($order->pageUrl())
        ->assertOk()
        ->assertSee('sms:+61412144625?&body='.rawurlencode("Hi, about my order {$order->reference()}: "))
        ->assertSee('tel:+61412144625');
});
