<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('agents:expire-runs')->everyMinute()->withoutOverlapping();

Schedule::command('gis:sync')->dailyAt('03:00')->timezone('Asia/Almaty')->withoutOverlapping();
