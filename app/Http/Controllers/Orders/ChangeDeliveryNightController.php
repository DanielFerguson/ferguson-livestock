<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\ChangeDeliveryNight;
use App\Exceptions\CannotChangeOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeDeliveryNightRequest;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;

/**
 * The night picker on a customer's order page.
 */
class ChangeDeliveryNightController extends Controller
{
    public function __invoke(ChangeDeliveryNightRequest $request, Order $customerOrder, ChangeDeliveryNight $changeNight): RedirectResponse
    {
        $back = redirect($customerOrder->pageUrl().'#delivery');

        try {
            $changeNight($customerOrder->load('drop'), $request->string('night')->value());
        } catch (CannotChangeOrder $exception) {
            return $back->withErrors(['night' => $exception->getMessage()], 'night');
        }

        return $back->with('order-page.night', "Done. We’ll deliver on {$customerOrder->deliveryNight()}.");
    }
}
