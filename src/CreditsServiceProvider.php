<?php

namespace Packstub\Credits;

use Illuminate\Support\ServiceProvider;
use Packstub\Credits\Commands\AuditCommand;
use Packstub\Credits\Commands\ExpireCommand;

class CreditsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/packstub-credits.php', 'packstub-credits');

        $this->app->singleton(CreditsManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/packstub-credits.php' => config_path('packstub-credits.php')], 'packstub-credits-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'packstub-credits-migrations');

            $this->commands([AuditCommand::class, ExpireCommand::class]);
        }
    }
}
