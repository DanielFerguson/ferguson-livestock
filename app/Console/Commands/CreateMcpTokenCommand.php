<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\McpToken;
use App\Models\User;
use Illuminate\Console\Command;

class CreateMcpTokenCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mcp:token {name : What the token is for, e.g. "Claude Code on my laptop"} {--days= : Expire the token after this many days}';

    /**
     * @var string
     */
    protected $description = 'Create a bearer token that lets an AI client use the MCP server as the admin';

    public function handle(): int
    {
        $adminEmail = config('shop.admin_email');

        $admin = is_string($adminEmail) && $adminEmail !== ''
            ? User::query()->whereRaw('lower(email) = ?', [strtolower($adminEmail)])->first()
            : null;

        if (! $admin instanceof User) {
            $this->error('There is no admin user. Set SHOP_ADMIN_EMAIL and run the AdminUserSeeder first.');

            return self::FAILURE;
        }

        $days = $this->option('days');

        if ($days !== null && (! is_numeric($days) || (int) $days < 1)) {
            $this->error('--days must be a whole number of days, 1 or more.');

            return self::FAILURE;
        }

        $name = $this->argument('name');

        if (! is_string($name) || $name === '') {
            $this->error('Give the token a name.');

            return self::FAILURE;
        }

        ['token' => $token, 'plainText' => $plainText] = McpToken::issue(
            $admin,
            $name,
            $days === null ? null : now()->addDays((int) $days)->toImmutable(),
        );

        $this->info("Created token #{$token->id} for {$admin->email}. It is shown once; store it now:");
        $this->line($plainText);

        return self::SUCCESS;
    }
}
