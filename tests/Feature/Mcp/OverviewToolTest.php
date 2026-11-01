<?php

declare(strict_types=1);

use App\Mcp\Servers\FergusonLivestockServer;
use App\Mcp\Tools\Overview\GetOverviewTool;
use App\Models\Drop;
use App\Models\Order;
use App\Models\StripeEvent;
use App\Models\Subscriber;
use App\Models\User;

beforeEach(function (): void {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->admin = User::factory()->create(['email' => 'daniel@example.com']);
});

it('summarises the business', function (): void {
    $drop = Drop::factory()->open()->withStock()->create(['name' => 'November drop']);
    Subscriber::factory()->count(2)->create();
    Subscriber::factory()->create(['unsubscribed_at' => now()]);
    StripeEvent::query()->create(['id' => 'evt_1', 'type' => 'charge.refunded', 'payload' => [], 'received_at' => now(), 'last_error' => 'boom']);

    FergusonLivestockServer::actingAs($this->admin)
        ->tool(GetOverviewTool::class)
        ->assertOk()
        ->assertSee('November drop')
        ->assertSee('"subscribed":2')
        ->assertSee('"opted_out":1')
        ->assertSee('"failing":1');

    expect($drop->status()->value)->toBe('live');
});

it('copes with an empty shop', function (): void {
    FergusonLivestockServer::actingAs($this->admin)
        ->tool(GetOverviewTool::class)
        ->assertOk()
        ->assertSee('"featured_drop":null');
});

it('reports what Meta ads have brought in, by the shop’s own records', function (): void {
    $drop = Drop::factory()->open()->create(['opens_at' => now()->subDay()]);
    $fromAd = Subscriber::factory()->fromMetaAd()->create(['created_at' => now()->subDays(45)]);
    Order::factory()->for($drop)->create(['phone' => $fromAd->phone, 'total' => 16000]);

    FergusonLivestockServer::actingAs($this->admin)
        ->tool(GetOverviewTool::class)
        ->assertOk()
        ->assertSee('"meta_ads":{"sign_ups":1,"sign_ups_last_30_days":0,"featured_drop":{"sign_ups_before_drop":1,"of_whom_bought":1,"orders":1,"revenue":{"cents":16000,"formatted":"$160"}}}');
});
