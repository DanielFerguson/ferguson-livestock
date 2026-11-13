<?php

declare(strict_types=1);

use App\Enums\SmsStatus;
use App\Filament\Resources\OrderTexts\OrderTextResource;
use App\Filament\Resources\OrderTexts\Pages\ListOrderTexts;
use App\Jobs\SendOrderConfirmationText;
use App\Models\Drop;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SmsMessage;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));
    Queue::fake([SendOrderConfirmationText::class]);
});

function orderTextFor(string $customer, SmsStatus $status): SmsMessage
{
    $order = Order::factory()->for(Drop::factory()->open())->pickup()->create(['customer_name' => $customer]);
    $message = SmsMessage::factory()->orderConfirmation($status)->create(['to' => (string) $order->phone]);
    $order->update(['confirmation_sms_message_id' => $message->id]);

    return $message;
}

it('lists order texts, with those waiting for 8am counted in the menu', function (): void {
    $this->travelTo('2027-03-10 10:00:00'); // 9pm in Melbourne
    $waiting = orderTextFor('Wendy Waiting', SmsStatus::Queued);
    $sent = orderTextFor('Sam Sent', SmsStatus::Delivered);
    $broadcast = SmsMessage::factory()->create();

    livewire(ListOrderTexts::class)
        ->assertCanSeeTableRecords([$waiting, $sent])
        ->assertCanNotSeeTableRecords([$broadcast])
        ->assertSee(['Wendy Waiting', 'Sends at 8am']);

    expect(OrderTextResource::getNavigationBadge())->toBe('1');
});

it('sends a waiting text now, leaving one already sent alone', function (): void {
    $waiting = orderTextFor('Wendy Waiting', SmsStatus::Queued);
    $sent = orderTextFor('Sam Sent', SmsStatus::Sent);

    livewire(ListOrderTexts::class)
        ->selectTableRecords([$waiting, $sent])
        ->callAction(TestAction::make('sendNow')->table()->bulk());

    Queue::assertPushed(SendOrderConfirmationText::class, 1);
    Queue::assertPushed(SendOrderConfirmationText::class, fn (SendOrderConfirmationText $job): bool => $job->messageId === $waiting->id && $job->evenInQuietHours);
});

it('texts a drop’s orders from the dashboard, asking first in quiet hours', function (): void {
    $this->travelTo('2027-03-10 10:00:00'); // 9pm in Melbourne
    $drop = Drop::factory()->open()->create();
    $order = Order::factory()->for($drop)->pickup()->create(['customer_name' => 'Alec Carson', 'phone' => '+61493108989']);
    OrderItem::factory()->for($order)->for(Product::factory()->create(['name' => '5kg Beef Box']))->create();

    livewire(ListOrderTexts::class)
        ->callAction('textDrop', data: ['drop' => $drop->id, 'quiet_hours' => false])
        ->assertHasFormErrors(['quiet_hours' => 'accepted']);
    Queue::assertNothingPushed();

    livewire(ListOrderTexts::class)
        ->callAction('textDrop', data: ['drop' => $drop->id, 'quiet_hours' => true])
        ->assertHasNoFormErrors()
        ->assertNotified('Texting 1 person');

    expect($order->refresh()->confirmationText?->status)->toBe(SmsStatus::Queued);
    Queue::assertPushed(SendOrderConfirmationText::class, fn (SendOrderConfirmationText $job): bool => $job->messageId === $order->confirmation_sms_message_id && $job->evenInQuietHours);
});
