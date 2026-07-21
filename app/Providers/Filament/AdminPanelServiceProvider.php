<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\AdminModuleRegistry;
use App\Filament\Pages\Dashboard;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Illuminate\View\View;

final class AdminPanelServiceProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([])
            ->widgets([])
            ->navigation($this->navigation(...))
            ->renderHook(
                PanelsRenderHook::TOPBAR_START,
                fn (): View => view('filament.partials.module-switcher', [
                    'groups' => AdminModuleRegistry::groups(),
                    'activeKey' => AdminModuleRegistry::activeGroupKey(),
                ]),
            )
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
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * Scopes the sidebar to the current module: the Dashboard link is always
     * present, and every other item belongs to whichever module the current
     * request is active in (see {@see AdminModuleRegistry::activeGroupKey()}).
     */
    private function navigation(NavigationBuilder $builder): NavigationBuilder
    {
        $items = Dashboard::getNavigationItems();

        $activeKey = AdminModuleRegistry::activeGroupKey();

        if ($activeKey === null) {
            return $builder->items($items);
        }

        $activeGroup = collect(AdminModuleRegistry::groups())
            ->firstWhere('key', $activeKey);

        if ($activeGroup !== null) {
            $items = [...$items, ...AdminModuleRegistry::registeredNavigationItemsFor($activeGroup)];
        }

        return $builder->items([...$items, ...AdminModuleRegistry::navigationItems(onlyGroupKey: $activeKey)]);
    }
}
