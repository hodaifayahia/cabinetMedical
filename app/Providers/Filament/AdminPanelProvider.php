<?php

namespace App\Providers\Filament;

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Filament\Pages\PlatformDashboard;
use App\Filament\Widgets\AdminOverview;
use App\Filament\Widgets\CabinetGrowth;
use App\Filament\Widgets\LicenceExpiryRadar;
use App\Filament\Widgets\PendingCabinets;
use App\Http\Middleware\EnforceSessionLock;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Drclick')
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->colors([
                // The teal the product uses everywhere else (app.css sets
                // --primary to hsl(186 100% 20%)), so the back office reads
                // as the same product rather than a stock Filament install.
                'primary' => Color::hex('#005C66'),
                'gray' => Color::Slate,
                'info' => Color::Sky,
            ])
            ->maxContentWidth(Width::ScreenTwoExtraLarge)
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make('Clients')
                    ->icon(Heroicon::OutlinedBuildingOffice2),
                NavigationGroup::make('Licences & activations')
                    ->icon(Heroicon::OutlinedKey),
                NavigationGroup::make('Site public')
                    ->icon(Heroicon::OutlinedGlobeAlt),
                NavigationGroup::make('Administration')
                    ->icon(Heroicon::OutlinedCog6Tooth),
            ])
            ->unsavedChangesAlerts()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                PlatformDashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AdminOverview::class,
                CabinetGrowth::class,
                LicenceExpiryRadar::class,
                PendingCabinets::class,
            ])
            // The panel's own CSP allows inline styles, so the visual layer
            // ships as one stylesheet here instead of adding a Vite build
            // step for a custom Filament theme.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.theme')->render(),
            )
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_START,
                fn (): string => view('filament.sidebar-summary')->render(),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                EnforceSessionLock::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
