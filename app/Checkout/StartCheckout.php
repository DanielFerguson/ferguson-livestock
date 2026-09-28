<?php

namespace App\Checkout;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Exceptions\DropNotOpen;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidCheckout;
use App\Exceptions\PaymentProviderUnavailable;
use App\Exceptions\TooManyCheckoutAttempts;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Payments\NewCheckoutSession;
use App\Payments\PaymentGateway;
use App\Stock\StockLedger;
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

    /**
     * A looser ceiling for scripts that throw their cookies away between tries.
     */
    private const int TRIES_PER_IP_ADDRESS = 30;

    private const int TRY_WINDOW_SECONDS = 600;

    public function __construct(
        private StockLedger $stock,
        private PaymentGateway $payments,
        private OrderPayments $orders,
    ) {}

    /**
     * @return string the URL of Stripe's payment page
     *
     * @throws TooManyCheckoutAttempts
     * @throws InvalidCheckout
     * @throws DropNotOpen
     * @throws InsufficientStock
     * @throws PaymentProviderUnavailable
     */
    public function __invoke(CheckoutRequest $request): string
    {
        $fingerprint = hash('sha256', $request->sessionId);

        $this->limitTries($fingerprint, $request->ip);

        $drop = Drop::query()->with('items.product')->findOrFail($request->drop->id);
        $quantities = $this->validated($drop, $request);

        $this->letGoOfEarlierCheckouts($fingerprint);

        $deliveryDay = $request->deliveryMethod === DeliveryMethod::Delivery ? $request->deliveryDay : null;
        $order = $this->stock->reserve($drop, $quantities, $request->deliveryMethod, $deliveryDay, $fingerprint, now()->addMinutes(self::PAYMENT_PAGE_MINUTES)->toImmutable());

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
            "checkout:visitor:{$fingerprint}:{$ip}" => self::TRIES_PER_VISITOR,
            "checkout:ip:{$ip}" => self::TRIES_PER_IP_ADDRESS,
        ];

        foreach ($limits as $key => $tries) {
            if (RateLimiter::tooManyAttempts($key, $tries)) {
                throw new TooManyCheckoutAttempts(RateLimiter::availableIn($key));
            }
        }

        foreach (array_keys($limits) as $key) {
            RateLimiter::hit($key, self::TRY_WINDOW_SECONDS);
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

            if (! $item instanceof DropItem || $item->product->type === ProductType::Delivery) {
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
