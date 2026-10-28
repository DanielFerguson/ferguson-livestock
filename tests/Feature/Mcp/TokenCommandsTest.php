<?php

declare(strict_types=1);

use App\Models\McpToken;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(fn () => config(['shop.admin_email' => 'daniel@example.com']));

it('creates a token for the admin and shows it once', function (): void {
    $admin = User::factory()->create(['email' => 'Daniel@Example.com']);

    expect(Artisan::call('mcp:token', ['name' => 'Claude Code', '--days' => 30]))->toBe(0)
        ->and(Artisan::output())->toContain('flmcp_');

    $token = McpToken::query()->sole();

    expect($token->user_id)->toBe($admin->id)
        ->and($token->name)->toBe('Claude Code')
        ->and($token->expires_at?->isFuture())->toBeTrue();
});

it('will not create a token when there is no admin user', function (): void {
    expect(Artisan::call('mcp:token', ['name' => 'Claude Code']))->toBe(1)
        ->and(McpToken::query()->count())->toBe(0);
});

it('rejects a nonsense expiry', function (): void {
    User::factory()->create(['email' => 'daniel@example.com']);

    expect(Artisan::call('mcp:token', ['name' => 'Claude Code', '--days' => 'soon']))->toBe(1)
        ->and(McpToken::query()->count())->toBe(0);
});

it('revokes one token', function (): void {
    $admin = User::factory()->create(['email' => 'daniel@example.com']);
    $keep = McpToken::factory()->for($admin)->create();
    $revoke = McpToken::factory()->for($admin)->create();

    expect(Artisan::call('mcp:revoke', ['id' => $revoke->id]))->toBe(0)
        ->and(McpToken::query()->findOrFail($revoke->id)->revoked_at)->not->toBeNull()
        ->and(McpToken::query()->findOrFail($keep->id)->revoked_at)->toBeNull();
});

it('revokes every token', function (): void {
    $admin = User::factory()->create(['email' => 'daniel@example.com']);
    McpToken::factory()->for($admin)->count(2)->create();

    expect(Artisan::call('mcp:revoke', ['--all' => true]))->toBe(0)
        ->and(McpToken::query()->whereNull('revoked_at')->count())->toBe(0);
});
