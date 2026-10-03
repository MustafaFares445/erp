<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\AdminModuleRegistry;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;

/**
 * Presentation helpers for module "workspaces": one sidebar destination whose `tabs` are existing
 * Filament resources. The tabs reuse each resource's own list page, table, policy and URL — nothing
 * is copied — so a workspace only decides what is *shown together*, never what a user may do.
 *
 * Workspace items are declared in {@see AdminModuleRegistry::groups()}; this class only reads them.
 *
 * @phpstan-import-type ModuleItem from AdminModuleRegistry
 * @phpstan-import-type WorkspaceTab from AdminModuleRegistry
 * @phpstan-import-type WorkspaceTool from AdminModuleRegistry
 */
final class WorkspaceNavigation
{
    /**
     * The tabs of a workspace the current user may open. A user who can see at least one tab sees the
     * workspace; they never get a tab that would end in a 403.
     *
     * @param  ModuleItem  $item
     * @return list<WorkspaceTab>
     */
    public static function accessibleTabs(array $item): array
    {
        return array_values(array_filter($item['tabs'] ?? [], self::tabAccessible(...)));
    }

    /**
     * @param  ModuleItem  $item
     * @return list<WorkspaceTool>
     */
    public static function accessibleTools(array $item): array
    {
        return array_values(array_filter(
            $item['tools'] ?? [],
            static fn (array $tool): bool => AdminModuleRegistry::resolveLink($tool['link']) !== null,
        ));
    }

    /**
     * A tool's destination, with any preset table filters (e.g. Low Stock on the Stock Levels list).
     *
     * @param  WorkspaceTool  $tool
     */
    public static function toolUrl(array $tool): ?string
    {
        $url = AdminModuleRegistry::resolveLink($tool['link']);

        if ($url === null || ! isset($tool['filters'])) {
            return $url;
        }

        return $url.'?'.http_build_query(['tableFilters' => $tool['filters']]);
    }

    /** @param  WorkspaceTab  $tab */
    public static function tabAccessible(array $tab): bool
    {
        $resource = $tab['link'];

        if (AdminModuleRegistry::resolveLink($resource) === null) {
            return false;
        }

        if (isset($tab['page']) && method_exists($resource, 'canAccessPage')) {
            return (bool) $resource::canAccessPage($tab['page']);
        }

        return true;
    }

    /** @param  WorkspaceTab  $tab */
    public static function tabUrl(array $tab): string
    {
        return $tab['link']::getUrl($tab['page'] ?? 'index');
    }

    /** @param  ModuleItem  $item */
    public static function firstUrl(array $item): ?string
    {
        $tabs = self::accessibleTabs($item);

        return $tabs === [] ? null : self::tabUrl($tabs[0]);
    }

    /**
     * Whether the current request is on any page of the tab's resource (list, view, create, edit…),
     * so the sidebar keeps the workspace highlighted while a record is open.
     *
     * @param  WorkspaceTab  $tab
     */
    public static function tabOwnsCurrentRoute(array $tab): bool
    {
        $resource = $tab['link'];

        if (! request()->routeIs($resource::getRouteBaseName().'.*')) {
            return false;
        }

        if (! isset($tab['page'])) {
            return true;
        }

        return method_exists($resource, 'workspacePage') && $resource::workspacePage() === $tab['page'];
    }

    /** @param  ModuleItem  $item */
    public static function isActive(array $item): bool
    {
        foreach ($item['tabs'] ?? [] as $tab) {
            if (self::tabOwnsCurrentRoute($tab)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The sidebar entry for a workspace: its own label and icon, landing on the first accessible tab.
     *
     * @param  ModuleItem  $item
     */
    public static function navigationItem(array $item): NavigationItem
    {
        return NavigationItem::make($item['label'])
            ->label(fn (): string => __($item['label']))
            ->icon($item['icon'] ?? Heroicon::OutlinedSquares2x2)
            ->url(fn (): ?string => self::firstUrl($item))
            ->isActiveWhen(fn (): bool => self::isActive($item));
    }

    /**
     * Each workspace tab's own list-page class, mapped to the workspace it belongs to and the tab it
     * is. The tab bar is rendered through page-scoped render hooks (see AdminPanelServiceProvider)
     * rather than the request route, because Livewire updates re-render the page on a different route.
     *
     * @return array<string, array{item: ModuleItem, tab: WorkspaceTab}>
     */
    public static function listPageScopes(): array
    {
        $scopes = [];

        foreach (AdminModuleRegistry::groups() as $group) {
            foreach ($group['items'] as $item) {
                foreach ($item['tabs'] ?? [] as $tab) {
                    $resource = $tab['link'];
                    if (! is_subclass_of($resource, Resource::class)) {
                        continue;
                    }

                    $registration = $resource::getPages()[$tab['page'] ?? 'index'] ?? null;
                    if ($registration !== null) {
                        $scopes[$registration->getPage()] = ['item' => $item, 'tab' => $tab];
                    }
                }
            }
        }

        return $scopes;
    }
}
