<?php

use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
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

Schedule::command('demo-ai:recover')
    ->everyMinute()
    ->preventOverlapsUsing((new CacheEventMutex(app(CacheFactory::class)))->useStore('demo-ai'))
    ->withoutOverlapping(3)
    ->when(static fn (): bool => (bool) config('demo_ai.scheduler_enabled'));

Schedule::exec('/usr/bin/timeout --signal=TERM --kill-after=5s 100s '.escapeshellarg(PHP_BINARY).' '
    .escapeshellarg(base_path('artisan')).' queue:work demo-ai --queue=demo-ai --stop-when-empty --max-time=40 --timeout=45 --sleep=1 --tries=1')
    ->name('demo-ai:bounded-worker')
    ->everyMinute()
    ->preventOverlapsUsing((new CacheEventMutex(app(CacheFactory::class)))->useStore('demo-ai'))
    ->withoutOverlapping(3)
    ->when(static fn (): bool => (bool) config('demo_ai.scheduler_enabled') && (bool) config('demo_ai.enabled'));
