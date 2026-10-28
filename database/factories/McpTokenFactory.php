<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\McpToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<McpToken>
 */
class McpTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Claude Code',
            'token_hash' => McpToken::hash(McpToken::PREFIX.Str::random(48)),
        ];
    }

    public function revoked(): self
    {
        return $this->state(['revoked_at' => now()->subMinute()]);
    }

    public function expired(): self
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }

    /**
     * Hash a known plain-text token so a test can present it.
     */
    public function withPlainText(string $plainText): self
    {
        return $this->state(['token_hash' => McpToken::hash($plainText)]);
    }
}
