<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\AnnounceArrival;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;

/**
 * The "We're here" button on a pickup customer's order page. Whatever happens, the customer is told what to expect,
 * and given the number to ring if the farm can't be told this way.
 */
class AnnounceArrivalController extends Controller
{
    public function __invoke(Order $customerOrder, AnnounceArrival $announce): RedirectResponse
    {
        $customerOrder->load('drop');
        $phone = config()->string('shop.phone.display');

        // The push is queued, so this only fails if the queue can't take it. That's reported, and they're asked to call.
        $pushed = $announce->isOpenFor($customerOrder) ? rescue(fn (): bool => $announce($customerOrder), null) : null;

        return redirect($customerOrder->pageUrl().'#arrival')->with('order-page.arrival', match ($pushed) {
            true => "Thanks, we know you’re here! Dan or Tahlia will be out in a moment. If no one’s out in 5 minutes, call us on {$phone}.",
            false => "We know you’re here, and we’ll be out in a moment. If no one’s out in 5 minutes, call us on {$phone}.",
            null => "Sorry, we couldn’t pass that on. Call or text us on {$phone} and we’ll come out to meet you.",
        });
    }
}
