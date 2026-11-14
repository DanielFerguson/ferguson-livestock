<?php

declare(strict_types=1);

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\SmsStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SmsMessage;
use Illuminate\Support\Facades\URL;

it('gives each order its own unguessable secret for its page link', function (): void {
    [$first, $second] = Order::factory()->count(2)->create()->all();

    expect($first->access_token)->toMatch('/^[A-Za-z0-9]{16}$/')
        ->and($second->access_token)->toMatch('/^[A-Za-z0-9]{16}$/')
        ->and($first->access_token)->not->toBe($second->access_token);
});

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
