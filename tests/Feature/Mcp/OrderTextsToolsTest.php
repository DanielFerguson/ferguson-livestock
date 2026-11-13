<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Jobs\SendOrderConfirmationText;
use App\Mcp\Servers\FergusonLivestockServer;
use App\Mcp\Tools\Orders\PrepareOrderTextsTool;
use App\Mcp\Tools\Orders\SendOrderTextsTool;
use App\Models\Drop;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Server\Testing\PendingTestResponse;

beforeEach(function (): void {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->server = FergusonLivestockServer::actingAs(User::factory()->create(['email' => 'daniel@example.com']));
    $this->travelTo('2027-03-10 02:00:00'); // 1pm in Melbourne
});

/**
 * @param  array<string, mixed>  $attributes
 */
function orderToText(Drop $drop, string $customer, ?string $phone, array $attributes = []): Order
{
    $order = Order::factory()->for($drop)->pickup()->create(['customer_name' => $customer, 'phone' => $phone, ...$attributes]);
    OrderItem::factory()->for($order)->for(Product::factory()->create(['name' => '5kg Beef Box']))->create();

    return $order;
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<array-key, mixed>
 */
function prepareOrderTexts(PendingTestResponse $server, array $arguments): array
{
    $response = $server->tool(PrepareOrderTextsTool::class, $arguments);
    $response->assertOk();

    $content = (new ReflectionMethod($response, 'structuredContent'))->invoke($response);

    return is_array($content) ? $content : [];
}

it('shows each person’s text and who’s left out, then sends one text per person once', function (): void {
    Queue::fake([SendOrderConfirmationText::class]);
    $drop = Drop::factory()->open()->create(['delivery_days' => ['2026-11-14', '2026-11-15']]);
    $first = orderToText($drop, 'Elvis Gaboyo', '+61405339250');
    $second = orderToText($drop, 'Elvis Gaboyo', '+61405339250');
    $alec = orderToText($drop, 'Alec Carson', '+61493108989');
    $collected = orderToText($drop, 'Robert Ferguson', '+61459449392', ['status' => OrderStatus::Fulfilled]);
    $cash = orderToText($drop, 'Olly Richardson', null, ['status' => OrderStatus::CashOwed]);
    $texted = orderToText($drop, 'Sam Texted', '+61400000005', ['confirmation_texted_at' => now()]);
    Subscriber::factory()->unsubscribed()->create(['phone' => '+61400000006']);
    $stopped = orderToText($drop, 'Pat Stopped', '+61400000006');

    $preview = prepareOrderTexts($this->server, ['drop_id' => $drop->id]);

    expect(data_get($preview, 'people'))->toBe(2)
        ->and(data_get($preview, 'texts.*.orders'))->toBe([[$first->reference(), $second->reference()], [$alec->reference()]])
        ->and(data_get($preview, 'texts.0.text'))->toStartWith('Ferguson Livestock: Hi Elvis, thanks for your orders')
        ->and(data_get($preview, 'skipped'))->toBe([
            ['order' => $collected->reference(), 'name' => 'Robert Ferguson', 'reason' => 'Already collected or delivered'],
            ['order' => $cash->reference(), 'name' => 'Olly Richardson', 'reason' => 'No Australian mobile number'],
            ['order' => $texted->reference(), 'name' => 'Sam Texted', 'reason' => 'Already texted'],
            ['order' => $stopped->reference(), 'name' => 'Pat Stopped', 'reason' => 'Has opted out of texts'],
        ])
        ->and(data_get($preview, 'texts_in_total'))->toBe(4)
        ->and(data_get($preview, 'estimated_cost.cents'))->toBe(4 * 8);

    $this->server->tool(SendOrderTextsTool::class, ['drop_id' => $drop->id, 'confirmation_token' => data_get($preview, 'confirmation_token')])
        ->assertOk()->assertSee('"queued":2');
    $this->server->tool(SendOrderTextsTool::class, ['drop_id' => $drop->id, 'confirmation_token' => data_get($preview, 'confirmation_token')])
        ->assertHasErrors();

    Queue::assertPushed(SendOrderConfirmationText::class, 2);
    Queue::assertPushed(SendOrderConfirmationText::class, fn (SendOrderConfirmationText $job): bool => $job->orderIds === [$first->id, $second->id] && $job->evenInQuietHours);
});

it('won’t send in quiet hours unless the owner agreed to', function (): void {
    Queue::fake([SendOrderConfirmationText::class]);
    $this->travelTo('2027-03-10 10:00:00'); // 9pm in Melbourne
    $drop = Drop::factory()->open()->create();
    orderToText($drop, 'Alec Carson', '+61493108989');
    $token = data_get(prepareOrderTexts($this->server, ['drop_id' => $drop->id]), 'confirmation_token');

    $this->server->tool(SendOrderTextsTool::class, ['drop_id' => $drop->id, 'confirmation_token' => $token])->assertHasErrors();
    Queue::assertNothingPushed();

    $this->server->tool(SendOrderTextsTool::class, ['drop_id' => $drop->id, 'confirmation_token' => $token, 'acknowledge_quiet_hours' => true])->assertOk();
    Queue::assertPushed(SendOrderConfirmationText::class, 1);
});

it('refuses a token once the orders have changed', function (): void {
    Queue::fake([SendOrderConfirmationText::class]);
    $drop = Drop::factory()->open()->create();
    orderToText($drop, 'Alec Carson', '+61493108989');
    $token = data_get(prepareOrderTexts($this->server, ['drop_id' => $drop->id]), 'confirmation_token');
    orderToText($drop, 'New Buyer', '+61400000007');

    $this->server->tool(SendOrderTextsTool::class, ['drop_id' => $drop->id, 'confirmation_token' => $token])->assertHasErrors();

    Queue::assertNothingPushed();
});
