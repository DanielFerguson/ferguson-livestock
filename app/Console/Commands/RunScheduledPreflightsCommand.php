<?php

namespace App\Console\Commands;

use App\Actions\RunDropPreflight;
use App\Models\Drop;
use App\Models\User;
use App\Notifications\DropPreflightFinished;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class RunScheduledPreflightsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'drops:preflight';

    /**
     * @var string
     */
    protected $description = 'Check drops opening in the next 10 minutes and tell the admin whether they are ready';

    public function handle(RunDropPreflight $preflight): int
    {
        $drops = Drop::query()
            ->published()
            ->whereNull('preflight_ran_at')
            ->whereBetween('opens_at', [now(), now()->addMinutes(10)])
            ->get();

        foreach ($drops as $drop) {
            $this->notifyAdmin(new DropPreflightFinished($drop, $preflight($drop)));
            $this->info("Checked {$drop->name}.");
        }

        return self::SUCCESS;
    }

    private function notifyAdmin(DropPreflightFinished $notification): void
    {
        $email = config('shop.admin_email');

        if (! is_string($email) || $email === '') {
            $this->warn('SHOP_ADMIN_EMAIL is not set, so nobody was told.');

            return;
        }

        $admin = User::where('email', $email)->first();

        $admin !== null ? $admin->notify($notification) : Notification::route('mail', $email)->notify($notification);
    }
}
