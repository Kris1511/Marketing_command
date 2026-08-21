<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks — Marketing Command
|--------------------------------------------------------------------------
|
| Run the scheduler worker to execute these tasks automatically:
|   php artisan schedule:work
|
| Or on a production server, add to cron (runs every minute):
|   * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
|
*/

// Process due scheduled posts every minute.
// withoutOverlapping() prevents two runs from processing the same post simultaneously.
Schedule::command('posts:process-scheduled')
    ->everyMinute()
    ->withoutOverlapping(5)   // lock expires after 5 minutes (prevents stuck locks)
    ->runInBackground();
