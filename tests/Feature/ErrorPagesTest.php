<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

it('shows a friendly page, with a way back, for each error a customer can meet', function (int $status, string $heading): void {
    Route::get("/trigger-{$status}", fn () => abort($status))->middleware('web');

    $this->get("/trigger-{$status}")
        ->assertStatus($status)
        ->assertSee($heading)
        ->assertSee('Ferguson Livestock')
        ->assertSee('Back home')
        ->assertSee('<meta name="robots" content="noindex, follow">', false);
})->with([
    'the session ran out' => [419, 'This page has expired.'],
    'too many tries' => [429, 'Slow down a moment.'],
    'a server error' => [500, 'Something went wrong on our side.'],
    'down for a moment' => [503, 'We’ll be right back.'],
]);

it('asks people to wait before trying again when they’ve done something too often', function (): void {
    Route::get('/trigger-429', fn () => abort(429))->middleware('web');

    $this->get('/trigger-429')->assertSee('wait a minute');
});

it('has indexes for the lookups orders are fetched by', function (string $table, string $column): void {
    expect(Schema::hasIndex($table, [$column]))->toBeTrue();
})->with([
    'an order’s drop' => ['orders', 'drop_id'],
    'an order’s items' => ['order_items', 'order_id'],
]);
