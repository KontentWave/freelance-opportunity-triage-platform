<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('opportunity:poll-mailbox')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->when(static fn (): bool => filter_var(
        config('opportunity_mailbox.enabled'),
        FILTER_VALIDATE_BOOL,
    ));
