<?php

namespace App\Console\Commands;

use App\Support\LiveSiteCheck;
use Illuminate\Console\Command;

class SiteCheckCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'site:check
        {url : The deployed site, e.g. https://www.fergusonlivestock.com.au}
        {--staging : Expect every page to be hidden from search engines}';

    /**
     * @var string
     */
    protected $description = 'Check a deployed copy of the site keeps every URL, redirect and SEO signal';

    public function handle(): int
    {
        $checks = (new LiveSiteCheck($this->argument('url'), staging: (bool) $this->option('staging')))->run();

        $this->table(['', 'Check', 'Detail'], array_map(
            fn (array $check): array => [$check['passed'] ? '✓' : '✗', $check['check'], $check['detail']],
            $checks,
        ));

        $failed = count(array_filter($checks, fn (array $check): bool => ! $check['passed']));

        if ($failed > 0) {
            $this->error("{$failed} of ".count($checks).' checks failed.');

            return self::FAILURE;
        }

        $this->info('All '.count($checks).' checks passed.');

        return self::SUCCESS;
    }
}
