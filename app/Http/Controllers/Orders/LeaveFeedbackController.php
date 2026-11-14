<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeaveFeedbackRequest;
use App\Mail\NewFeedbackNotification;
use App\Models\Order;
use App\Models\OrderFeedback;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;

/**
 * The feedback form at the bottom of a customer's order page. Leaving it again replaces it, and each time tells the farm.
 */
class LeaveFeedbackController extends Controller
{
    public function __invoke(LeaveFeedbackRequest $request, Order $customerOrder): RedirectResponse
    {
        $comment = $request->validated('comment');

        $feedback = OrderFeedback::query()->updateOrCreate(
            ['order_id' => $customerOrder->id],
            ['rating' => $request->integer('rating'), 'comment' => is_string($comment) ? $comment : null],
        );

        Mail::to(config()->string('shop.email'))->queue(new NewFeedbackNotification($feedback, edited: ! $feedback->wasRecentlyCreated));

        return redirect($customerOrder->pageUrl().'#feedback')->with('order-page.feedback', 'Thank you! We read every one.');
    }
}
