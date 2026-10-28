<?php

declare(strict_types=1);

namespace App\Checkout;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Exceptions\DropNotOpen;
use App\Exceptions\FailedBotCheck;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCheckout;
use App\Exceptions\PaymentProviderUnavailable;
use App\Exceptions\TooManyCheckoutAttempts;
use App\Jobs\SettleOrderWhenPaymentPageCloses;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Payments\NewCheckoutSession;
use App\Payments\PaymentGateway;
use App\Stock\StockLedger;
use App\Support\Turnstile;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Holds the stock for an order and hands the customer to Stripe's payment page.
 *
 * The server decides everything here: whether the drop is open, what the items cost, and whether there's
 * enough left. The order form's own checks are only for the customer's convenience.
 */
final readonly class StartCheckout
{
    /**
     * Stripe's shortest allowed time on the payment page is 30 minutes.
     */
    private const int PAYMENT_PAGE_MINUTES = 31;

    private const int TRIES_PER_VISITOR = 5;

    private const int VISITOR_WINDOW_SECONDS = 600;

    /**
     * A looser ceiling for scripts that throw their cookies away between tries.
     */
    private const int TRIES_PER_IP_ADDRESS = 30;

    /**
     * Longer than a hold lasts (the payment page's 31 minutes plus the sweep's grace period), so one address can't
     * keep more than its tries' worth of stock on hold by waiting for the window to reset.
     */
    private const int IP_ADDRESS_WINDOW_SECONDS = 35 * 60;

    public function __construct(
        private StockLedger $stock,
        private PaymentGateway $payments,
        private OrderPayments $orders,
        private Turnstile $botCheck,
    ) {}

    /**
     * Every try is counted before anything else happens, so a script that keeps calling this (one request can
     * carry thousands of calls) costs a cache lookup each, not a call to Cloudflare or a database query.
     *
     * @return string the URL of Stripe's payment page
     *
     * @throws TooManyCheckoutAttempts
     * @throws FailedBotCheck
     * @throws InvalidCheckout
     * @throws DropNotOpen
     * @throws InsufficientStock
     * @throws PaymentProviderUnavailable
     */
    public function __invoke(CheckoutRequest $request): string
    {
        $fingerprint = hash('sha256', $request->sessionId);

        $this->limitTries($fingerprint, $request->ip);

        if (! $this->botCheck->passes($request->botCheckToken, $request->ip)) {
            throw new FailedBotCheck;
        }

        $drop = Drop::query()->with('items.product')->findOrFail($request->dropId);
        $quantities = $this->validated($drop, $request);

        $this->letGoOfEarlierCheckouts($fingerprint);

        $deliveryDay = $request->deliveryMethod === DeliveryMethod::Delivery ? $request->deliveryDay : null;
        $order = $this->stock->reserve($drop, $quantities, $request->deliveryMethod, $deliveryDay, $fingerprint, now()->addMinutes(self::PAYMENT_PAGE_MINUTES)->toImmutable());

        // Queued before Stripe is called, so the stock comes back even if this request stops part-way.
        SettleOrderWhenPaymentPageCloses::dispatch($order->id)->delay(SettleClosedCheckout::dueAt($order));

        try {
            $session = $this->payments->createCheckoutSession($this->paymentPage($order));
        } catch (PaymentProviderUnavailable $exception) {
            $this->orders->markExpired($order);

            throw $exception;
        }

        $order->update(['stripe_checkout_session_id' => $session->id]);

        return (string) $session->url;
    }

    private function limitTries(string $fingerprint, string $ip): void
    {
        $limits = [
            "checkout:visitor:{$fingerprint}:{$ip}" => [self::TRIES_PER_VISITOR, self::VISITOR_WINDOW_SECONDS],
            "checkout:ip:{$ip}" => [self::TRIES_PER_IP_ADDRESS, self::IP_ADDRESS_WINDOW_SECONDS],
        ];

        foreach ($limits as $key => [$tries]) {
            if (RateLimiter::tooManyAttempts($key, $tries)) {
                throw new TooManyCheckoutAttempts(RateLimiter::availableIn($key));
            }
        }

        foreach ($limits as $key => [, $window]) {
            RateLimiter::hit($key, $window);
        }
    }

    /**
     * @return array<int, int> drop item ID => quantity, with the delivery fee added for deliveries
     *
     * @throws InvalidCheckout
     */
    private function validated(Drop $drop, CheckoutRequest $request): array
    {
        $quantities = array_filter($request->quantities, fn (int $quantity): bool => $quantity > 0);

        if ($quantities === []) {
            throw new InvalidCheckout('Choose a box or at least one extra.');
        }

        $boxes = 0;

        foreach ($quantities as $dropItemId => $quantity) {
            $item = $drop->items->firstWhere('id', $dropItemId);

            // firstWhere() matches loosely, so "07" would find item 7 and slip past its limit under a second key.
            if (! $item instanceof DropItem || $item->id !== $dropItemId || $item->product->type === ProductType::Delivery) {
                throw new InvalidCheckout('Something in your order isn’t part of this drop.');
            }

            if ($quantity > $item->max_per_order) {
                throw new InvalidCheckout("You can order up to {$item->max_per_order} of {$item->product->name}.");
            }

            $boxes += $item->product->type === ProductType::Box ? $quantity : 0;
        }

        if ($boxes > 1) {
            throw new InvalidCheckout('Choose one box per order.');
        }

        if ($request->deliveryMethod === DeliveryMethod::Delivery) {
            $quantities[$this->deliveryFee($drop, $request)->id] = 1;
        }

        return $quantities;
    }

    /**
     * @throws InvalidCheckout
     */
    private function deliveryFee(Drop $drop, CheckoutRequest $request): DropItem
    {
        if ($request->deliveryDay === null) {
            throw new InvalidCheckout('Choose a delivery day.');
        }

        if (! in_array($request->deliveryDay->toDateString(), $drop->delivery_days, true)) {
            throw new InvalidCheckout('That delivery day isn’t available. Choose another.');
        }

        $fee = $drop->items->first(fn (DropItem $item): bool => $item->product->type === ProductType::Delivery);

        if (! $fee instanceof DropItem) {
            throw new InvalidCheckout('Delivery isn’t available for this drop. Choose farm pickup instead.');
        }

        return $fee;
    }

    /**
     * Someone who comes back from Stripe and checks out again (or presses back and resubmits) mustn't hold
     * stock twice. Their earlier payment page is closed first, unless they'd already paid on it.
     */
    private function letGoOfEarlierCheckouts(string $fingerprint): void
    {
        $earlier = Order::query()->where('session_fingerprint', $fingerprint)->where('status', OrderStatus::Pending)->get();

        foreach ($earlier as $order) {
            if ($order->stripe_checkout_session_id === null) {
                $this->orders->markExpired($order);

                continue;
            }

            $this->orders->apply($order, $this->payments->expireCheckoutSession($order->stripe_checkout_session_id));
        }
    }

    private function paymentPage(Order $order): NewCheckoutSession
    {
        $returnTo = fn (string $route): string => route($route).'?session_id={CHECKOUT_SESSION_ID}';

        return new NewCheckoutSession(
            orderPublicId: $order->public_id,
            lineItems: array_values($order->load('items.dropItem')->items
                ->map(fn (OrderItem $item): array => ['price' => $item->dropItem->stripe_price_id, 'quantity' => $item->quantity])
                ->all()),
            collectShippingAddress: $order->delivery_method === DeliveryMethod::Delivery,
            expiresAt: $order->expires_at,
            successUrl: $returnTo('order-confirmed'),
            cancelUrl: $returnTo('checkout.cancel'),
        );
    }
}
