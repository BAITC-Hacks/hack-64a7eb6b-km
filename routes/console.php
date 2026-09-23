<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('agents:expire-runs')->everyMinute()->withoutOverlapping();
