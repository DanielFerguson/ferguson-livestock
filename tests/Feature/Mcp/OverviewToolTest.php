<?php

declare(strict_types=1);

use App\Mcp\Servers\FergusonLivestockServer;
use App\Mcp\Tools\Overview\GetOverviewTool;
use App\Models\Drop;
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
