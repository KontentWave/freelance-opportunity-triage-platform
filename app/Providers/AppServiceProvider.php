<?php

namespace App\Providers;

use App\Domain\Opportunities\Contracts\OpportunityEmailParser;
use App\Infrastructure\Email\UpworkJobAlertParser;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OpportunityEmailParser::class, UpworkJobAlertParser::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
