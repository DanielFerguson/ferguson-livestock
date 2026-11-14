<?php

declare(strict_types=1);

use App\Mail\OrderConfirmation;
use Illuminate\Support\Facades\Mail;

it('emails the order to the address given at checkout', function (): void {
    Mail::fake();
    $order = samsWeekendOrder();

    $this->post(route('orders.email', ['customerOrder' => $order->access_token]))
        ->assertRedirect($order->pageUrl().'#email')
        ->assertSessionHas('order-page.email', 'Sent to s•••@example.com. It should arrive in a minute or two.');

    Mail::assertQueued(OrderConfirmation::class, fn (OrderConfirmation $mail): bool => $mail->hasTo('sam@example.com') && $mail->order->is($order));
});

it('sends it at most once every ten minutes', function (): void {
    Mail::fake();
    $order = samsWeekendOrder();
    $emailIt = fn () => $this->post(route('orders.email', ['customerOrder' => $order->access_token]));

    $emailIt();
    $emailIt()->assertSessionHas('order-page.email', 'We’ve just sent it to s•••@example.com. Give it a few minutes, and check your junk folder.');
    Mail::assertQueued(OrderConfirmation::class, 1);

    $this->travel(10)->minutes();
    $emailIt();
    Mail::assertQueued(OrderConfirmation::class, 2);
});

it('shows only a hint of the address on the page', function (): void {
    $this->get(samsWeekendOrder()->pageUrl())
        ->assertOk()
        ->assertSeeInOrder(['Email me my order', 's•••@example.com'])
        ->assertDontSee('sam@example.com');
});

it('has nothing to send for an order without an email', function (): void {
    Mail::fake();
    $order = samsWeekendOrder(['email' => null]);

    $this->get($order->pageUrl())->assertOk()->assertDontSee('Email me my order');
    $this->post(route('orders.email', ['customerOrder' => $order->access_token]))->assertNotFound();
    Mail::assertNothingQueued();
});
