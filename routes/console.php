<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('sync:run')->everyMinute()->withoutOverlapping();
