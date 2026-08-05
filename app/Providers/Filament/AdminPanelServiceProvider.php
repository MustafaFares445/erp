<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\AdminModuleRegistry;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\InventoryLowStock;
use App\Filament\Widgets\InventoryPendingDocuments;
use App\Filament\Widgets\InventoryRecentMovements;
use App\Filament\Widgets\InventoryStockValue;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
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
            ->maxContentWidth(Width::Full)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([])
            ->widgets([
                InventoryPendingDocuments::class,
                InventoryLowStock::class,
                InventoryStockValue::class,
                InventoryRecentMovements::class,
            ])
            ->assets([
                AlpineComponent::make('customer-delivery-map', resource_path('js/filament/customer-delivery-map.js')),
                AlpineComponent::make('customer-location-picker', resource_path('js/filament/customer-location-picker.js')),
                Css::make('customer-delivery-map', resource_path('css/filament/customer-delivery-map.css')),
                Css::make('customer-location-picker', resource_path('css/filament/customer-location-picker.css')),
                Css::make('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'),
                Js::make('leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'),
            ])
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
     *
     * A module whose group declares `sections` (currently only Inventory)
     * renders as real collapsible {@see NavigationGroup} objects, one per
     * section, instead of one flat unlabeled list — see
     * specs/012-inventory-module-consolidation/plan.md's Structure Decision
     * for why `NavigationBuilder::items()` alone collapses everything into a
     * single group regardless of each item's own declared group.
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
            $sections = $activeGroup['sections'] ?? [];

            if ($sections !== []) {
                foreach ($sections as $section) {
                    $sectionItems = [
                        ...AdminModuleRegistry::registeredNavigationItemsFor($activeGroup, onlySection: $section['key']),
                        ...AdminModuleRegistry::navigationItems(onlyGroupKey: $activeKey, onlySection: $section['key']),
                    ];

                    if ($sectionItems === []) {
                        continue;
                    }

                    $builder->group(
                        NavigationGroup::make(fn (): string => __($section['label']))
                            ->items($sectionItems),
                    );
                }

                return $builder->items($items);
            }

            $items = [...$items, ...AdminModuleRegistry::registeredNavigationItemsFor($activeGroup)];
        }

        return $builder->items([...$items, ...AdminModuleRegistry::navigationItems(onlyGroupKey: $activeKey)]);
    }
}
