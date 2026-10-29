<?php

declare(strict_types=1);

namespace App\Http\Controllers\Checkout;

use App\Checkout\OrderPayments;
use App\Enums\OrderStatus;
use App\Exceptions\PaymentProviderUnavailable;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Payments\PaymentGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where Stripe's "back" link lands. The payment page is closed at Stripe first, so the customer can't pay after
 * their stock has gone back on sale; if they'd already paid, they see their confirmation instead.
 */
class CancelCheckoutController extends Controller
{
    private const string CANCELLED = 'Your order was cancelled and nothing was charged.';

    public function __invoke(Request $request, OrderPayments $orders): RedirectResponse
    {
        $sessionId = $request->string('session_id')->value();
        $order = preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId) === 1
            ? Order::query()->where('stripe_checkout_session_id', $sessionId)->first()
            : null;

        if ($order === null) {
            return to_route('order');
        }

        // Only an open payment page needs closing at Stripe. A settled order is answered from what the shop
        // already knows, so replaying an old link can't spend the shop's Stripe rate limit.
        if ($order->status !== OrderStatus::Pending) {
            return in_array($order->status, [OrderStatus::Expired, OrderStatus::Failed], true)
                ? $this->backToOrderPage($order)->with('checkout.notice', self::CANCELLED)
                : to_route('order-confirmed', ['session_id' => $sessionId]);
        }

        try {
            $session = app(PaymentGateway::class)->expireCheckoutSession($sessionId);
        } catch (PaymentProviderUnavailable) {
            // The sweep closes the payment page and puts the stock back once Stripe answers again.
            return $this->backToOrderPage($order);
        }

        $orders->apply($order, $session);

        if ($session->isPaid() || $session->isAwaitingPayment()) {
            return to_route('order-confirmed', ['session_id' => $sessionId]);
        }

        return $this->backToOrderPage($order)->with('checkout.notice', self::CANCELLED);
    }

    /**
     * Someone who came in through a drop's early-access link goes back to it, since the public order page may
     * not show their drop yet.
     */
    private function backToOrderPage(Order $order): RedirectResponse
    {
        $drop = $order->drop;

        return $order->via_early_access && $drop->acceptsEarlyOrders()
            ? to_route('early-access', ['token' => $drop->early_access_token])
            : to_route('order');
    }
}
