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
use Illuminate\Support\Facades\RateLimiter;

/**
 * The feedback form at the bottom of a customer's order page. Leaving it again replaces it.
 *
 * The farm hears about new feedback and about changes, but at most once every ten minutes per order and never for a
 * resubmission that changes nothing, so the page can't be used to fill the farm's inbox. The admin always shows the
 * latest.
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

        if ($feedback->wasRecentlyCreated || $feedback->wasChanged()) {
            RateLimiter::attempt("order-feedback-email:{$customerOrder->id}", 1, function () use ($feedback): void {
                Mail::to(config()->string('shop.email'))->queue(new NewFeedbackNotification($feedback, edited: ! $feedback->wasRecentlyCreated));
            }, decaySeconds: 600);
        }

        return redirect($customerOrder->pageUrl().'#feedback')->with('order-page.feedback', 'Thank you! We read every one.');
    }
}
