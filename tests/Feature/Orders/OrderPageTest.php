<?php

declare(strict_types=1);

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Models\Order;
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

        expect($order->shortLink())->toBe("fergs.au/{$order->access_token}");
    });

    it('gives texts the page’s full address when there’s no short domain', function (): void {
        config(['shop.short_domain' => null]);
        URL::useOrigin('https://fergusonlivestock.com.au');
        $order = samsWeekendOrder();

        expect($order->shortLink())->toBe("fergusonlivestock.com.au/orders/{$order->access_token}");
    });
});
