<?php

use App\Filament\Resources\SmsBroadcasts\SmsBroadcastResource;
use App\Filament\Resources\SmsReplies\SmsReplyResource;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Models\Drop;
use App\Models\Product;
use App\Models\SmsBroadcast;
use App\Models\SmsMessage;
use App\Models\Subscriber;
use App\Models\User;
use Database\Seeders\DemoDropSeeder;
use Database\Seeders\ProductSeeder;

beforeEach(function () {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->seed([ProductSeeder::class, DemoDropSeeder::class]);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));
});

it('renders the admin pages in a real browser without errors', function () {
    visit([
        '/admin',
        '/admin/drops',
        '/admin/drops/create',
        '/admin/drops/'.Drop::sole()->id.'/edit',
        '/admin/products',
        '/admin/products/'.Product::where('slug', 'beef-box-10kg')->sole()->id.'/edit',
    ])->assertNoJavaScriptErrors();
});

it('renders the texting pages in a real browser without errors', function () {
    $sent = SmsBroadcast::factory()->sent()->create();
    $subscriber = Subscriber::factory()->create();
    SmsMessage::factory()->for($sent, 'broadcast')->for($subscriber)->create();
    SmsMessage::factory()->for($subscriber)->reply('What time Saturday?')->create();
    $draft = SmsBroadcast::factory()->create();

    visit([
        SubscriberResource::getUrl(),
        SubscriberResource::getUrl('edit', ['record' => $subscriber]),
        SmsBroadcastResource::getUrl(),
        SmsBroadcastResource::getUrl('create'),
        SmsBroadcastResource::getUrl('view', ['record' => $draft]),
        SmsBroadcastResource::getUrl('edit', ['record' => $draft]),
        SmsBroadcastResource::getUrl('view', ['record' => $sent]),
        SmsReplyResource::getUrl(),
    ])->assertNoJavaScriptErrors();
});
