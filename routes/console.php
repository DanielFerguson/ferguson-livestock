<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Laravel Cloud wakes the app for every scheduled run, so these run hourly and the app, database and cache can
// sleep in between. Delayed jobs on the queue do the exact timing; these catch anything a job missed.
Schedule::command('drops:preflight')->hourly()->onOneServer()->withoutOverlapping();
Schedule::command('sms:send-scheduled')->hourly()->onOneServer()->withoutOverlapping();
Schedule::command('orders:sweep')->hourly()->onOneServer()->withoutOverlapping();

// A failed job keeps the order or payment it was handling. Thirty days is long enough to look into it.
Schedule::command('queue:prune-failed --hours=720')->daily()->onOneServer();
