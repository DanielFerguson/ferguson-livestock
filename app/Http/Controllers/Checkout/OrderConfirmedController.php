<?php

namespace App\Http\Controllers\Checkout;

use App\Checkout\OrderPayments;
use App\Enums\OrderStatus;
use App\Exceptions\PaymentProviderUnavailable;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Payments\PaymentGateway;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where Stripe sends customers after paying. Customers often arrive before Stripe's webhook does, so a pending
 * order is checked with Stripe directly.
 */
class OrderConfirmedController extends Controller
{
    public function __invoke(Request $request, OrderPayments $orders): View|RedirectResponse
    {
        $sessionId = $request->string('session_id')->value();
        $order = preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId) === 1
            ? Order::query()->where('stripe_checkout_session_id', $sessionId)->first()
            : null;

        if ($order !== null && $order->status === OrderStatus::Pending) {
            try {
                $orders->apply($order, app(PaymentGateway::class)->retrieveCheckoutSession($sessionId));
            } catch (PaymentProviderUnavailable) {
                // Shown as "confirming"; the webhook or the sweep settles it.
            }
        }

        if ($order === null || in_array($order->status, [OrderStatus::Expired, OrderStatus::Failed], true)) {
            return to_route('order');
        }

        return view('pages.order-confirmed', ['order' => $order->load('items.product')]);
    }
}
