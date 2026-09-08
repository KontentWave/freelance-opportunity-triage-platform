<?php

namespace App\Providers;

use App\Domain\Mailbox\Contracts\MailboxClient;
use App\Domain\Mailbox\Contracts\MonotonicClock;
use App\Domain\Mailbox\Data\MailboxConfiguration;
use App\Domain\Opportunities\Contracts\OpportunityEmailParser;
use App\Infrastructure\Email\UpworkJobAlertParser;
use App\Infrastructure\Email\WebklexImapMailboxClient;
use App\Infrastructure\Time\SystemMonotonicClock;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OpportunityEmailParser::class, UpworkJobAlertParser::class);
        $this->app->singleton(
            MailboxConfiguration::class,
            fn (Application $application): MailboxConfiguration => MailboxConfiguration::fromArray(
                (array) config('opportunity_mailbox'),
                isTesting: $application->environment('testing'),
            ),
        );
        $this->app->bind(MailboxClient::class, WebklexImapMailboxClient::class);
        $this->app->singleton(MonotonicClock::class, SystemMonotonicClock::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
