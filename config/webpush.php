<?php

declare(strict_types=1);

use NotificationChannels\WebPush\PushSubscription;

return [

    /*
     * The key pair that signs pushes to the admin's phones (VAPID). Generate it once per environment with
     * `php artisan webpush:vapid --show` and never change it: a new pair cuts off every phone signed up with the
     * old one. Apple refuses pushes without a real subject, so it falls back to the shop's address.
     */
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', env('SHOP_URL', 'https://fergusonlivestock.com.au')),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    'model' => PushSubscription::class,

    'table_name' => 'push_subscriptions',

    /*
     * Null is the app's own database. The package's default falls back to "mysql" when DB_CONNECTION isn't set.
     */
    'database_connection' => env('WEBPUSH_DB_CONNECTION'),

    'client_options' => [],

    'automatic_padding' => env('WEBPUSH_AUTOMATIC_PADDING', true),

];
