<?php

declare(strict_types=1);

use App\Filament\Widgets\MetaAdsOverview;
use App\Models\Drop;
use App\Models\Order;
use App\Models\Subscriber;
use App\Models\User;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));
});

it('shows ad sign-ups, how many bought in the current drop, and what ad customers spent', function (): void {
    $drop = Drop::factory()->open()->create(['name' => 'November drop', 'opens_at' => now()->subDay()]);
    $buyer = Subscriber::factory()->fromMetaAd()->create(['created_at' => now()->subDays(45)]);
    Subscriber::factory()->fromMetaAd()->create(['created_at' => now()->subDays(45)]);
    Order::factory()->for($drop)->create(['phone' => $buyer->phone, 'total' => 16000]);

    livewire(MetaAdsOverview::class)
        ->assertSeeInOrder(['Wait-list sign-ups from ads', '2', 'Ad sign-ups who bought', '1 of 2', 'In November drop', 'Sales to ad customers', '$160', '1 paid order in November drop']);
});

it('stays off the dashboard until Meta is set up or an ad brings someone in', function (): void {
    expect(MetaAdsOverview::canView())->toBeFalse();

    enableMetaAds(conversionsApi: false);
    expect(MetaAdsOverview::canView())->toBeTrue();

    config(['services.meta.pixel_id' => null]);
    Subscriber::factory()->fromMetaAd()->create();
    expect(MetaAdsOverview::canView())->toBeTrue();
});
