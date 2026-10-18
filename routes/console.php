<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('app:reconcile-messaging')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('app:prune-records')->dailyAt('03:15')->withoutOverlapping()->onOneServer();
Schedule::command('queue:prune-failed', ['--hours' => 24 * 14])->daily();
