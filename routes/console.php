<?php

use Illuminate\Support\Facades\Schedule;

// Runs every minute; the command itself waits until INTERVAL has passed.
Schedule::command('mam:sync --if-due')
    ->everyMinute()
    ->withoutOverlapping()
    ->when(fn () => config('seedbox.auto_sync'));

Schedule::command('model:prune')->daily();
