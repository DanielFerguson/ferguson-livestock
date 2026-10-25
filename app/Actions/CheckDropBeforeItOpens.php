<?php

namespace App\Actions;

use App\Models\Drop;
use App\Models\User;
use App\Notifications\DropPreflightFinished;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Runs a drop's pre-flight check shortly before it opens and tells the admin the result, once per drop.
 */
final readonly class CheckDropBeforeItOpens
{
    public const int MINUTES_BEFORE_OPENING = 10;

    public function __construct(private RunDropPreflight $preflight) {}

    /**
     * Stops the hourly command and a timed check, or two timed checks, checking the same drop at once.
     */
    public static function lockFor(Drop $drop): Lock
    {
        return Cache::lock("drop-preflight:{$drop->id}", 120);
    }

    /**
     * @return bool whether the drop was checked, rather than left to a check already running or done
     */
    public function __invoke(Drop $drop): bool
    {
        return self::lockFor($drop)->get(function () use ($drop): bool {
            if ($drop->refresh()->preflight_ran_at !== null) {
                return false;
            }

            $this->notifyAdmin(new DropPreflightFinished($drop, ($this->preflight)($drop)));

            return true;
        }) === true;
    }

    private function notifyAdmin(DropPreflightFinished $notification): void
    {
        $email = config('shop.admin_email');

        if (! is_string($email) || $email === '') {
            Log::warning("Checked {$notification->drop->name} before it opens, but SHOP_ADMIN_EMAIL is not set, so nobody was told.");

            return;
        }

        $admin = User::where('email', $email)->first();

        $admin !== null ? $admin->notify($notification) : Notification::route('mail', $email)->notify($notification);
    }
}
