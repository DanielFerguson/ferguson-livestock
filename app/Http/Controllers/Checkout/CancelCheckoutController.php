<?php

namespace App\Http\Controllers\Checkout;

use App\Checkout\OrderPayments;
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
    public function __invoke(Request $request, OrderPayments $orders): RedirectResponse
    {
        $sessionId = $request->string('session_id')->value();
        $order = preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId) === 1
            ? Order::query()->where('stripe_checkout_session_id', $sessionId)->first()
            : null;

        if ($order === null) {
            return to_route('order');
        }

        try {
            $session = app(PaymentGateway::class)->expireCheckoutSession($sessionId);
        } catch (PaymentProviderUnavailable) {
            // The sweep closes the payment page and puts the stock back once Stripe answers again.
            return to_route('order');
        }

        $orders->apply($order, $session);

        if ($session->isPaid() || $session->isAwaitingPayment()) {
            return to_route('order-confirmed', ['session_id' => $sessionId]);
        }

        return to_route('order')->with('checkout.notice', 'Your order was cancelled and nothing was charged.');
    }
}
