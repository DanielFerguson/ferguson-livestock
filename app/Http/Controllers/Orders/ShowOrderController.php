<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\ChangeDeliveryNight;
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
    public function __invoke(Order $customerOrder, ChangeDeliveryNight $nights): View
    {
        $customerOrder->load(['items.product', 'drop']);

        return view('pages.order-details', [
            'order' => $customerOrder,
            'alsoInText' => $this->alsoInText($customerOrder),
            'nightChoices' => $nights->choicesFor($customerOrder),
        ]);
    }

    /**
     * The customer's other orders that their order text covered. The text only links this one, so its page links them.
     *
     * Texts group orders by phone number, which anyone can type at checkout, so only orders with the same email are
     * linked: someone who ordered with your number and their own email can't reach your order from theirs.
     *
     * @return Collection<int, Order>
     */
    private function alsoInText(Order $order): Collection
    {
        if ($order->confirmation_sms_message_id === null || $order->email === null) {
            return new Collection;
        }

        return Order::query()
            ->where('confirmation_sms_message_id', $order->confirmation_sms_message_id)
            ->whereKeyNot($order->id)
            ->whereIn('status', OrderStatus::placed())
            ->whereRaw('lower(email) = ?', [mb_strtolower($order->email)])
            ->orderBy('id')
            ->get();
    }
}
