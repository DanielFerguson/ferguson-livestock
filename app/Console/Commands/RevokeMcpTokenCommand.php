<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\McpToken;
use Illuminate\Console\Command;

class RevokeMcpTokenCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mcp:revoke {id? : The token id shown when it was created} {--all : Revoke every token}';

    /**
     * @var string
     */
    protected $description = 'Revoke one MCP token, or all of them';

    public function handle(): int
    {
        $tokens = McpToken::query()->whereNull('revoked_at');

        if ($this->option('all')) {
            $count = $tokens->update(['revoked_at' => now()]);
            $this->info("Revoked {$count} token(s).");

            return self::SUCCESS;
        }

        $id = $this->argument('id');

        if (! is_numeric($id)) {
            $this->error('Give a token id, or pass --all.');

            foreach (McpToken::query()->whereNull('revoked_at')->get() as $token) {
                $this->line("#{$token->id}  {$token->name}  last used ".($token->last_used_at?->diffForHumans() ?? 'never'));
            }

            return self::FAILURE;
        }

        $count = $tokens->whereKey((int) $id)->update(['revoked_at' => now()]);

        if ($count === 0) {
            $this->error("No active token with id {$id}.");

            return self::FAILURE;
        }

        $this->info("Revoked token #{$id}.");

        return self::SUCCESS;
    }
}
