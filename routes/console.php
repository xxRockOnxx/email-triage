<?php

use App\Jobs\PollGmailJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Polls Gmail on the interval configured via GMAIL_POLL_CRON in .env
 * (defaults to every 5 minutes — see config/gmail.php). Using a raw cron
 * expression rather than the fluent ->everyFiveMinutes() etc. helpers keeps
 * the interval fully configurable without code changes.
 *
 * withoutOverlapping() is a belt-and-suspenders pair with PollGmailJob's own
 * ShouldBeUnique — protects even if the scheduler itself double-fires.
 */
Schedule::call(function () {
    PollGmailJob::dispatch(config('gmail.account_email'));
})
    ->name('gmail-poll')
    ->cron(config('gmail.poll_cron', '*/5 * * * *'))
    ->withoutOverlapping();
