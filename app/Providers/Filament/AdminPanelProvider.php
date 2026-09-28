<?php

namespace App\Providers\Filament;

use App\Http\Middleware\PreventIndexing;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Vite;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /**
     * The site's sage green (#4a6741 is 600) at its own muted saturation. Filament's Color::hex() would
     * stretch it to a bright green, and buttons only get white text when 600 and 500 pass 4.5:1 contrast.
     *
     * @var array<int, string>
     */
    private const array SAGE = [
        50 => 'oklch(0.975 0.008 138.5)',
        100 => 'oklch(0.945 0.017 138.5)',
        200 => 'oklch(0.885 0.032 138.5)',
        300 => 'oklch(0.800 0.050 138.5)',
        400 => 'oklch(0.690 0.065 138.5)',
        500 => 'oklch(0.555 0.070 138.5)',
        600 => 'oklch(0.480 0.068 138.5)',
        700 => 'oklch(0.420 0.060 138.5)',
        800 => 'oklch(0.360 0.050 138.5)',
        900 => 'oklch(0.310 0.042 138.5)',
        950 => 'oklch(0.240 0.032 138.5)',
    ];

    /**
     * The greys, tinted from the site's cream (100 is close to #f5f2eb) down to its forest green (950), so the light
     * page reads warm and dark mode reads deep green instead of Filament's cold zinc. 500 and darker keep muted text
     * above 4.5:1 on white.
     *
     * @var array<int, string>
     */
    private const array NEUTRAL = [
        50 => 'oklch(0.982 0.005 90)',
        100 => 'oklch(0.962 0.009 90)',
        200 => 'oklch(0.925 0.013 92)',
        300 => 'oklch(0.868 0.017 100)',
        400 => 'oklch(0.720 0.020 115)',
        500 => 'oklch(0.550 0.022 125)',
        600 => 'oklch(0.450 0.024 132)',
        700 => 'oklch(0.375 0.026 138)',
        800 => 'oklch(0.300 0.028 142)',
        900 => 'oklch(0.235 0.028 144)',
        950 => 'oklch(0.185 0.026 144)',
    ];

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName(config()->string('shop.name'))
            ->brandLogo(fn () => view('filament.admin.logo'))
            ->brandLogoHeight('auto')
            ->favicon('/favicon-32x32.png')
            ->colors([
                'primary' => self::SAGE,
                'gray' => self::NEUTRAL,
            ])
            // The site's own self-hosted faces. Filament writes nothing for a local font without a URL, so the
            // @font-face rules come from the same Vite fonts the public pages use.
            ->font('Source Sans 3', provider: LocalFontProvider::class)
            ->serifFont('Cormorant Garamond', provider: LocalFontProvider::class)
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => Vite::fonts(['source-sans', 'cormorant']))
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('16rem')
            ->navigationGroups([
                NavigationGroup::make('Texts')->icon(Heroicon::OutlinedChatBubbleLeftRight),
                NavigationGroup::make('System')->icon(Heroicon::OutlinedCog6Tooth)->collapsed(),
            ])
            // An authenticator app is required outside local dev; recovery codes cover a lost phone.
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ], isRequired: ! app()->isLocal())
            ->databaseNotifications()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                PreventIndexing::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
