<?php

namespace App\Providers;

use App\Http\Requests\JoinWaitlistRequest;
use App\Payments\PaymentGateway;
use App\Payments\StripePaymentGateway;
use App\Sms\SmsGateway;
use App\Sms\TwilioSmsGateway;
use App\Stock\DropSnapshot;
use App\Support\Catalogue;
use App\Support\Faqs;
use App\Support\ResponsiveImages;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Read fresh each time: the featured drop's prices change with the clock and admin edits,
        // and the pages that use it are served from the response cache anyway.
        $this->app->bind(Catalogue::class, fn (): Catalogue => Catalogue::fromDatabase());

        $this->app->singleton(PaymentGateway::class, fn (): PaymentGateway => StripePaymentGateway::fromConfig());

        $this->app->singleton(SmsGateway::class, fn (): SmsGateway => TwilioSmsGateway::fromConfig());

        $this->app->singleton(ResponsiveImages::class, function (): ResponsiveImages {
            /** @var array<string, array{source: string, widths: non-empty-list<int>, aspect?: string}> $variants */
            $variants = File::json(resource_path('images/variants.json'), JSON_THROW_ON_ERROR);

            return new ResponsiveImages(
                variants: $variants,
                sourceDirectory: resource_path('images'),
                url: fn (string $path): string => Vite::asset($path),
            );
        });
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

        // Root-relative asset URLs keep srcsets short and work on every host (staging, previews, production).
        Vite::createAssetPathsUsing(fn (string $path): string => '/'.ltrim($path, '/'));

        View::composer(['pages.home', 'pages.beef-boxes', 'pages.delivery'], fn (\Illuminate\View\View $view) => $view->with('catalogue', $this->app->make(Catalogue::class)));
        View::composer(['pages.home', 'pages.faq'], fn (\Illuminate\View\View $view) => $view->with('faqs', $this->app->make(Faqs::class)->all()));
        View::composer(['components.announcement-bar', 'components.home.hero', 'pages.beef-boxes'], fn (\Illuminate\View\View $view) => $view->with('liveDrop', DropSnapshot::current()));

        RateLimiter::for('waitlist', fn (Request $request) => Limit::perMinutes(10, 5)
            ->by((string) $request->ip())
            ->response(fn () => redirect(JoinWaitlistRequest::formUrl(url()->previous()))
                ->withInput()
                ->withErrors([
                    'first_name' => 'You’ve tried a few times in a row. Please wait a few minutes and try again, or call us on '.config()->string('shop.phone.display').'.',
                ], 'waitlist')));
    }
}
