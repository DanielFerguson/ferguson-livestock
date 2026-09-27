<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $isProduction = $this->app->isProduction();

        // Catch lazy loading, missing attributes and silently discarded fills before they reach production.
        Model::shouldBeStrict(! $isProduction);

        DB::prohibitDestructiveCommands($isProduction);

        URL::forceHttps($isProduction);

        // Drop times are compared and converted a lot; immutable dates avoid accidental mutation.
        Date::use(CarbonImmutable::class);
    }
}
