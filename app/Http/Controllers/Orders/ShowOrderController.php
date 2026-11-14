<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Contracts\View\View;

/**
 * A customer's own page for their order, found by the secret in its link.
 */
class ShowOrderController extends Controller
{
    public function __invoke(Order $customerOrder): View
    {
        return view('pages.order-details', ['order' => $customerOrder->load(['items.product', 'drop'])]);
    }
}
