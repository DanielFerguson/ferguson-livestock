<?php

declare(strict_types=1);

use App\Models\Order;

it('gives each order its own unguessable secret for its page link', function (): void {
    [$first, $second] = Order::factory()->count(2)->create()->all();

    expect($first->access_token)->toMatch('/^[A-Za-z0-9]{16}$/')
        ->and($second->access_token)->toMatch('/^[A-Za-z0-9]{16}$/')
        ->and($first->access_token)->not->toBe($second->access_token);
});
