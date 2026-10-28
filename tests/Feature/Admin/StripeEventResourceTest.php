<?php

declare(strict_types=1);

use App\Filament\Resources\StripeEvents\Pages\ListStripeEvents;
use App\Jobs\ProcessStripeEvent;
use App\Models\StripeEvent;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));
});

function storedEvent(string $id, bool $processed): StripeEvent
{
    return StripeEvent::query()->create([
        'id' => $id, 'type' => 'checkout.session.completed', 'payload' => ['id' => $id, 'data' => ['object' => []]],
        'received_at' => now(), 'processed_at' => $processed ? now() : null, 'attempts' => $processed ? 1 : 3,
        'last_error' => $processed ? null : 'Database went away',
    ]);
}

it('lists Stripe’s events with any error', function (): void {
    $failed = storedEvent('evt_failed', processed: false);

    livewire(ListStripeEvents::class)
        ->assertCanSeeTableRecords([$failed])
        ->assertSee('Database went away');
});

it('retries an event that hasn’t been processed', function (): void {
    Queue::fake([ProcessStripeEvent::class]);
    $failed = storedEvent('evt_failed', processed: false);

    livewire(ListStripeEvents::class)->callAction(TestAction::make('retry')->table($failed));

    Queue::assertPushed(ProcessStripeEvent::class, fn (ProcessStripeEvent $job): bool => $job->eventId === 'evt_failed');
});

it('doesn’t offer to retry an event that worked', function (): void {
    $processed = storedEvent('evt_ok', processed: true);

    livewire(ListStripeEvents::class)->assertActionHidden(TestAction::make('retry')->table($processed));
});
