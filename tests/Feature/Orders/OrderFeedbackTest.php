<?php

declare(strict_types=1);

use App\Mail\NewFeedbackNotification;
use App\Models\Order;
use App\Models\OrderFeedback;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\post;

/**
 * Leave feedback from the order's page.
 *
 * @param  array<mixed>  $answers
 * @return TestResponse<Response>
 */
function leaveFeedback(Order $order, array $answers): TestResponse
{
    return post(route('orders.feedback', ['customerOrder' => $order->access_token]), $answers);
}

it('saves the customer’s stars and comment, and tells the farm', function (): void {
    Mail::fake();
    $order = samsWeekendOrder();

    leaveFeedback($order, ['rating' => 5, 'comment' => 'Best mince we’ve had.'])
        ->assertRedirect($order->pageUrl().'#feedback')
        ->assertSessionHas('order-page.feedback', 'Thank you! We read every one.');

    expect($order->feedback()->sole())->rating->toBe(5)->comment->toBe('Best mince we’ve had.');
    Mail::assertQueued(NewFeedbackNotification::class, fn (NewFeedbackNotification $mail): bool => $mail->hasTo(config()->string('shop.email')) && ! $mail->edited);
});

it('replaces their feedback when they leave it again', function (): void {
    Mail::fake();
    $order = samsWeekendOrder();
    OrderFeedback::factory()->for($order)->create(['rating' => 2, 'comment' => 'Running late.']);

    leaveFeedback($order, ['rating' => 4, 'comment' => '']);

    expect($order->feedback()->sole())->rating->toBe(4)->comment->toBeNull();
    Mail::assertQueued(NewFeedbackNotification::class, fn (NewFeedbackNotification $mail): bool => $mail->edited);
});

it('doesn’t email the farm again when resubmitted feedback changes nothing', function (): void {
    Mail::fake();
    $order = samsWeekendOrder();
    OrderFeedback::factory()->for($order)->create(['rating' => 4, 'comment' => 'Lovely']);

    leaveFeedback($order, ['rating' => 4, 'comment' => 'Lovely'])->assertSessionHas('order-page.feedback');

    Mail::assertNothingQueued();
});

it('emails the farm about one order’s feedback at most once every ten minutes', function (): void {
    Mail::fake();
    $order = samsWeekendOrder();

    leaveFeedback($order, ['rating' => 5]);
    leaveFeedback($order, ['rating' => 4]);
    Mail::assertQueued(NewFeedbackNotification::class, 1);

    $this->travel(10)->minutes();
    leaveFeedback($order, ['rating' => 3]);
    Mail::assertQueued(NewFeedbackNotification::class, 2);
    expect($order->feedback()->sole()->rating)->toBe(3);
});

it('shows the feedback they left, ready to change', function (): void {
    $order = samsWeekendOrder();
    OrderFeedback::factory()->for($order)->create(['rating' => 4, 'comment' => 'Best mince we’ve had.']);

    $this->get($order->pageUrl())->assertOk()->assertSeeInOrder(['How did we do?', 'Best mince we’ve had.', 'Update feedback']);
});

it('says what’s wrong with feedback it can’t take', function (array $answers, string $field, string $message): void {
    Mail::fake();
    $order = samsWeekendOrder();

    leaveFeedback($order, $answers)
        ->assertRedirect($order->pageUrl().'#feedback')
        ->assertSessionHasErrorsIn('feedback', [$field => $message]);

    expect($order->feedback()->exists())->toBeFalse();
    Mail::assertNothingQueued();
})->with([
    'no stars' => [['comment' => 'Lovely'], 'rating', 'Choose from 1 to 5 stars.'],
    'six stars' => [['rating' => 6], 'rating', 'Choose from 1 to 5 stars.'],
    'a comment that’s too long' => [['rating' => 5, 'comment' => str_repeat('a', 2001)], 'comment', 'Please keep it under 2,000 characters.'],
]);

it('tells the farm the stars, who left them and what they said', function (): void {
    $feedback = OrderFeedback::factory()->for(samsWeekendOrder())->create(['rating' => 4, 'comment' => 'Best mince ever']);

    // The stars are checked in the subject: the email's CSS inliner may write them as entities in the HTML.
    (new NewFeedbackNotification($feedback, edited: false))
        ->assertHasSubject("★★★★☆ from Sam Buyer for {$feedback->order->reference()}")
        ->assertSeeInOrderInHtml([$feedback->order->reference(), 'Sam Buyer', 'left feedback', 'Best mince ever']);
});

it('keeps what the customer typed as plain text in the farm’s email', function (): void {
    $order = samsWeekendOrder(['customer_name' => '[Win](https://evil.test) Sam']);
    $feedback = OrderFeedback::factory()->for($order)->create(['comment' => '<script>alert(1)</script> [link](https://evil.test)']);

    $html = (new NewFeedbackNotification($feedback, edited: false))->render();

    expect($html)
        ->toContain('&lt;script&gt;')
        ->not->toContain('<script>alert(1)</script>')
        ->not->toContain('href="https://evil.test"');
});
