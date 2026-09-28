<?php

use Illuminate\Support\Facades\Schedule;

// Laravel Cloud runs the scheduler every minute on one replica.
Schedule::command('drops:preflight')->everyMinute()->onOneServer()->withoutOverlapping();
Schedule::command('sms:send-scheduled')->everyMinute()->onOneServer()->withoutOverlapping();
Schedule::command('orders:sweep')->everyMinute()->onOneServer()->withoutOverlapping();
