<?php

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\SmsBroadcasts\SmsBroadcastResource;
use App\Filament\Resources\SmsReplies\SmsReplyResource;
use App\Filament\Resources\StripeEvents\StripeEventResource;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Models\Drop;
use App\Models\Order;
use App\Models\OrderItem;
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

it('renders the order pages in a real browser without errors', function () {
    $order = Order::factory()->create();
    OrderItem::factory()->for($order)->create();

    visit([
        OrderResource::getUrl(),
        OrderResource::getUrl('view', ['record' => $order]),
        OrderResource::getUrl('delivery-run', ['drop' => $order->drop]),
        StripeEventResource::getUrl(),
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

it('shows the logo, the site fonts and the admin theme on the dashboard', function () {
    visit('/admin')
        ->assertScript("document.querySelector('.fl-brand-mark').naturalWidth > 0")
        ->assertScript("getComputedStyle(document.querySelector('.fl-brand-mark')).width", '40px')
        ->assertScript("getComputedStyle(document.querySelector('.fi-header-heading')).fontFamily.includes('Cormorant Garamond')")
        ->assertScript("document.fonts.load('600 1em \"Cormorant Garamond\"').then(faces => faces.length > 0)")
        ->assertNoJavaScriptErrors();
});
