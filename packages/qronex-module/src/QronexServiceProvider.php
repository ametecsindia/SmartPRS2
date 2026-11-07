<?php

namespace Ametecs\Qronex;

use Ametecs\Qronex\Contracts\Host;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** Register in the host's bootstrap/providers.php and set config('qronex.host') to the host adapter. */
class QronexServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/qronex.php', 'qronex');
        $this->app->singleton(Host::class, fn ($app) => $app->make(config('qronex.host') ?: throw new \RuntimeException('Set qronex.host to the host adapter class.')));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'qronex');
        \Illuminate\Support\Facades\Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'qx');
        \Illuminate\Support\Facades\View::composer('qronex::*', fn ($v) => $v->with('host', app(Host::class)));
        $this->loadRoutesFrom(__DIR__.'/../routes/qronex.php');

        if ($this->app->runningInConsole()) {
            $this->commands([Console\SyncCommand::class]);
            $this->app->booted(function () {
                $s = $this->app->make(Schedule::class);
                $s->command('qronex:sync')->hourly()->withoutOverlapping();
            });
        }
    }
}
