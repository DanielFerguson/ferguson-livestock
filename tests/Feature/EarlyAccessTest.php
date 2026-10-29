<?php

declare(strict_types=1);

use App\Actions\Drops\DisableEarlyAccess;
use App\Actions\Drops\EnableEarlyAccess;
use App\Actions\RunDropPreflight;
use App\Checkout\CheckoutRequest;
use App\Checkout\StartCheckout;
use App\Enums\DeliveryMethod;
use App\Exceptions\CannotChangeDrop;
use App\Exceptions\DropNotOpen;
use App\Exceptions\InsufficientStock;
use App\Livewire\OrderForm;
use App\Mcp\Servers\FergusonLivestockServer;
use App\Mcp\Tools\Drops\DisableEarlyAccessTool;
use App\Mcp\Tools\Drops\EnableEarlyAccessTool;
use App\Models\Drop;
use App\Models\Order;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Tests\Fakes\FakePaymentGateway;
use Tests\Fixtures\StockedDrop;

use function Pest\Livewire\livewire;

/**
 * A drop that is still a draft, a week before it opens, with early access switched on.
 */
function earlyDrop(int $boxes = 5): StockedDrop
{
    $shop = StockedDrop::create(boxes: $boxes, drop: ['published_at' => null, 'opens_at' => now()->addWeek()]);
    $shop->drop->startEarlyAccess();

    return $shop;
}

function earlyCheckout(StockedDrop $shop, ?string $token, string $visitor = 'visitor-a'): string
{
    return app(StartCheckout::class)(new CheckoutRequest(
        $shop->drop->id, [$shop->box->id => 1], DeliveryMethod::Pickup, null, $visitor, '192.0.2.1', earlyAccessToken: $token,
    ));
}

it('shows an unpublished drop on its secret link, but nowhere public', function (): void {
    $shop = earlyDrop();

    $this->get(route('early-access', ['token' => $shop->drop->early_access_token]))
        ->assertOk()
        ->assertSee('Early access')
        ->assertSee('5kg Beef Box')
        ->assertSee('5 left')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    $this->get(route('order'))->assertDontSee('5kg Beef Box');
});

it('is a 404 for a wrong, replaced or switched-off link, or a closed drop', function (): void {
    $shop = earlyDrop();
    $old = $shop->drop->early_access_token;

    $this->get(route('early-access', ['token' => 'not-the-token']))->assertNotFound();

    $new = $shop->drop->startEarlyAccess();
    $this->get(route('early-access', ['token' => $old]))->assertNotFound();
    $this->get(route('early-access', ['token' => $new]))->assertOk();

    $shop->drop->closeNow();
    $this->get(route('early-access', ['token' => $new]))->assertNotFound();

    $shop->drop->refresh()->update(['closed_at' => null]);
    $shop->drop->stopEarlyAccess();
    $this->get(route('early-access', ['token' => $new]))->assertNotFound();
});

it('takes an order before the drop is public, from the same stock', function (): void {
    FakePaymentGateway::swap();
    $shop = earlyDrop(boxes: 5);

    expect(earlyCheckout($shop, $shop->drop->early_access_token))->toStartWith('https://checkout.stripe.test/');

    expect(Order::query()->sole()->via_early_access)->toBeTrue()
        ->and($shop->box->refresh()->available)->toBe(4);
});

it('still refuses an unopened drop without the right link', function (?string $token): void {
    FakePaymentGateway::swap();
    $shop = earlyDrop();

    expect(fn (): string => earlyCheckout($shop, $token))->toThrow(DropNotOpen::class);
    expect($shop->box->refresh()->available)->toBe(5);
})->with([null, 'a-guess', '']);

it('refuses the old link once it has been replaced', function (): void {
    FakePaymentGateway::swap();
    $shop = earlyDrop();
    $old = $shop->drop->early_access_token;
    $shop->drop->startEarlyAccess();

    expect(fn (): string => earlyCheckout($shop, $old))->toThrow(DropNotOpen::class);
});

it('stops taking early orders once the drop is closed', function (): void {
    FakePaymentGateway::swap();
    $shop = earlyDrop();
    $shop->drop->update(['closes_at' => now()->subMinute(), 'opens_at' => now()->subHour()]);

    expect(fn (): string => earlyCheckout($shop, $shop->drop->early_access_token))->toThrow(DropNotOpen::class);
});

it('lets early buyers and the public share the last box without overselling', function (): void {
    FakePaymentGateway::swap();
    $shop = earlyDrop(boxes: 1);

    earlyCheckout($shop, $shop->drop->early_access_token);
    $shop->drop->update(['published_at' => now(), 'opens_at' => now()->subMinute()]);

    expect(fn (): string => earlyCheckout($shop, null, 'visitor-b'))->toThrow(InsufficientStock::class);
    expect($shop->box->refresh()->available)->toBe(0);
});

it('keeps working through the link after the drop opens', function (): void {
    FakePaymentGateway::swap();
    $shop = earlyDrop();
    $shop->drop->update(['published_at' => now(), 'opens_at' => now()->subMinute()]);

    expect(earlyCheckout($shop, $shop->drop->early_access_token))->toStartWith('https://checkout.stripe.test/');
});

it('reads live stock from its own link’s feed', function (): void {
    $shop = earlyDrop(boxes: 3);
    $token = $shop->drop->early_access_token;

    $this->getJson(route('early-access.stock', ['token' => $token]))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('drop.state', 'live')
        ->assertJsonPath("items.{$shop->box->product->slug}.available", 3);

    $this->getJson(route('early-access.stock', ['token' => 'wrong']))->assertNotFound();
});

it('sends a customer who cancels back to the early-access link', function (): void {
    FakePaymentGateway::swap();
    $shop = earlyDrop();
    earlyCheckout($shop, $shop->drop->early_access_token);
    $order = Order::query()->sole();

    $this->get(route('checkout.cancel', ['session_id' => $order->stripe_checkout_session_id]))
        ->assertRedirect(route('early-access', ['token' => $shop->drop->early_access_token]));
});

describe('the order form', function (): void {
    it('starts checkout from the early-access link', function (): void {
        FakePaymentGateway::swap();
        $shop = earlyDrop();

        livewire(OrderForm::class, ['drop' => $shop->drop, 'earlyAccessToken' => $shop->drop->early_access_token])
            ->set('box', (string) $shop->box->id)
            ->set('deliveryMethod', 'pickup')
            ->call('checkout')
            ->assertRedirect('https://checkout.stripe.test/cs_test_1');
    });

    it('won’t open for the wrong link, or the link of another drop', function (): void {
        $shop = earlyDrop();

        livewire(OrderForm::class, ['drop' => $shop->drop, 'earlyAccessToken' => 'wrong'])->assertNotFound();
    });

    it('can’t have its link changed from the browser', function (): void {
        $shop = earlyDrop();

        livewire(OrderForm::class, ['drop' => $shop->drop, 'earlyAccessToken' => $shop->drop->early_access_token])
            ->set('earlyAccessToken', 'something-else');
    })->throws(CannotUpdateLockedPropertyException::class);
});

describe('switching it on and off', function (): void {
    it('makes a link once, keeps it on request, and replaces it on demand', function (): void {
        $drop = StockedDrop::create(drop: ['published_at' => null, 'opens_at' => now()->addWeek()])->drop;
        $enable = app(EnableEarlyAccess::class);

        $first = $enable($drop)->early_access_token;

        expect($first)->toBeString()->toHaveLength(40)
            ->and($enable($drop)->early_access_token)->toBe($first)
            ->and($enable($drop, newLink: true)->early_access_token)->not->toBe($first)
            ->and($drop->refresh()->earlyAccessUrl())->toBe(route('early-access', ['token' => $drop->early_access_token]));

        app(DisableEarlyAccess::class)($drop);

        expect($drop->refresh()->early_access_token)->toBeNull()->and($drop->earlyAccessUrl())->toBeNull();
    });

    it('won’t switch on for a drop with nothing to sell, or one that has closed', function (): void {
        expect(fn (): Drop => app(EnableEarlyAccess::class)(Drop::factory()->draft()->create()))
            ->toThrow(CannotChangeDrop::class, 'Add products');

        $closed = StockedDrop::create()->drop;
        $closed->closeNow();

        expect(fn (): Drop => app(EnableEarlyAccess::class)($closed->refresh()))
            ->toThrow(CannotChangeDrop::class, 'closed');
    });

    it('can be done by the MCP tools, which hand back the link', function (): void {
        config(['shop.admin_email' => 'daniel@example.com']);
        $server = FergusonLivestockServer::actingAs(User::factory()->create(['email' => 'daniel@example.com']));
        $drop = StockedDrop::create(drop: ['published_at' => null, 'opens_at' => now()->addWeek()])->drop;

        $server->tool(EnableEarlyAccessTool::class, ['drop_id' => $drop->id])->assertOk();
        $link = $drop->refresh()->earlyAccessUrl();
        expect($link)->not->toBeNull();

        $server->tool(EnableEarlyAccessTool::class, ['drop_id' => $drop->id])->assertOk();
        expect($drop->refresh()->earlyAccessUrl())->toBe($link);

        $server->tool(EnableEarlyAccessTool::class, ['drop_id' => $drop->id, 'new_link' => true])->assertOk();
        expect($drop->refresh()->earlyAccessUrl())->not->toBe($link);

        $server->tool(DisableEarlyAccessTool::class, ['drop_id' => $drop->id])->assertOk();
        expect($drop->refresh()->earlyAccessUrl())->toBeNull();

        $server->tool(EnableEarlyAccessTool::class, ['drop_id' => 99999])->assertHasErrors();
    });
});

it('doesn’t flag stock sold through the early link as held before opening', function (): void {
    FakePaymentGateway::swap();
    $shop = earlyDrop();
    earlyCheckout($shop, $shop->drop->early_access_token);

    $report = app(RunDropPreflight::class)($shop->drop->refresh());

    expect(implode(' ', $report['problems']))->not->toContain('before the drop has opened');
});
