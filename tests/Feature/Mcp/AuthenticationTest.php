<?php

declare(strict_types=1);

use App\Models\McpToken;
use App\Models\User;

const MCP_INITIALIZE = [
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'initialize',
    'params' => [
        'protocolVersion' => '2025-06-18',
        'capabilities' => [],
        'clientInfo' => ['name' => 'test', 'version' => '1.0'],
    ],
];

beforeEach(function (): void {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->admin = User::factory()->create(['email' => 'daniel@example.com']);
});

it('turns away a request with no token', function (): void {
    $this->postJson('/mcp/ferguson', MCP_INITIALIZE)->assertUnauthorized();
});

it('turns away a token we never issued', function (): void {
    $this->postJson('/mcp/ferguson', MCP_INITIALIZE, ['Authorization' => 'Bearer flmcp_nope'])->assertUnauthorized();
    $this->postJson('/mcp/ferguson', MCP_INITIALIZE, ['Authorization' => 'Bearer something-else'])->assertUnauthorized();
});

it('lets in a live token that belongs to the admin', function (): void {
    ['plainText' => $plainText, 'token' => $token] = McpToken::issue($this->admin, 'Claude Code');

    $this->postJson('/mcp/ferguson', MCP_INITIALIZE, ['Authorization' => "Bearer {$plainText}"])
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'Ferguson Livestock');

    expect(McpToken::query()->findOrFail($token->id)->last_used_at)->not->toBeNull();
});

it('turns away a revoked token', function (): void {
    McpToken::factory()->for($this->admin)->revoked()->withPlainText('flmcp_revoked')->create();

    $this->postJson('/mcp/ferguson', MCP_INITIALIZE, ['Authorization' => 'Bearer flmcp_revoked'])->assertUnauthorized();
});

it('turns away an expired token', function (): void {
    McpToken::factory()->for($this->admin)->expired()->withPlainText('flmcp_expired')->create();

    $this->postJson('/mcp/ferguson', MCP_INITIALIZE, ['Authorization' => 'Bearer flmcp_expired'])->assertUnauthorized();
});

it('turns away a token whose owner is not the admin', function (): void {
    $someone = User::factory()->create(['email' => 'someone@example.com']);
    McpToken::factory()->for($someone)->withPlainText('flmcp_someone')->create();

    $this->postJson('/mcp/ferguson', MCP_INITIALIZE, ['Authorization' => 'Bearer flmcp_someone'])->assertUnauthorized();
});

it('turns away every token when no admin email is configured', function (): void {
    ['plainText' => $plainText] = McpToken::issue($this->admin, 'Claude Code');
    config(['shop.admin_email' => null]);

    $this->postJson('/mcp/ferguson', MCP_INITIALIZE, ['Authorization' => "Bearer {$plainText}"])->assertUnauthorized();
});

it('stores only a hash of the token', function (): void {
    ['plainText' => $plainText, 'token' => $token] = McpToken::issue($this->admin, 'Claude Code');

    expect($token->token_hash)->not->toContain($plainText)->and($plainText)->toStartWith('flmcp_');
});
