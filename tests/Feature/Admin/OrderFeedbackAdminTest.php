<?php

declare(strict_types=1);

use App\Filament\Resources\OrderFeedback\Pages\ListOrderFeedback;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\OrderFeedback;
use App\Models\User;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));
});

it('lists what customers thought, with who left it and their order', function (): void {
    $order = Order::factory()->create(['customer_name' => 'Sam Buyer']);
    $feedback = OrderFeedback::factory()->for($order)->create(['rating' => 4, 'comment' => 'Best mince we’ve had.']);

    livewire(ListOrderFeedback::class)
        ->assertCanSeeTableRecords([$feedback])
        ->assertSee('★★★★☆')
        ->assertSee('Best mince we’ve had.')
        ->assertSee('Sam Buyer')
        ->assertSee($order->reference());
});

it('shows the customer’s page link and their feedback on the order', function (): void {
    $order = Order::factory()->create();
    OrderFeedback::factory()->for($order)->create(['rating' => 2, 'comment' => 'Too much fat on the steaks.']);

    livewire(ViewOrder::class, ['record' => $order->id])
        ->assertSee($order->access_token)
        ->assertSeeTextInOrder(['Feedback', '★★☆☆☆', 'Too much fat on the steaks.']);
});
