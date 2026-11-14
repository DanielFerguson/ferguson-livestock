<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Mail\OrderConfirmation;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Emails a customer their order again from its page. It only ever goes to the address they gave at checkout, and at
 * most once every ten minutes, so the page can't be used to fill someone's inbox.
 */
class EmailOrderController extends Controller
{
    public function __invoke(Order $customerOrder): RedirectResponse
    {
        $email = $customerOrder->email;

        abort_if($email === null, 404);

        $sent = RateLimiter::attempt("order-email:{$customerOrder->id}", 1, function () use ($customerOrder, $email): void {
            Mail::to($email)->queue(new OrderConfirmation($customerOrder));
        }, decaySeconds: 600);

        return redirect($customerOrder->pageUrl().'#email')->with('order-page.email', $sent !== false
            ? "Sent to {$customerOrder->emailHint()}. It should arrive in a minute or two."
            : "We’ve just sent it to {$customerOrder->emailHint()}. Give it a few minutes, and check your junk folder.");
    }
}
