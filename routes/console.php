<?php

use Illuminate\Support\Facades\Schedule;

// Laravel Cloud runs the scheduler every minute on one replica.
Schedule::command('drops:preflight')->everyMinute()->onOneServer()->withoutOverlapping();
