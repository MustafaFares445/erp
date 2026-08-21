<?php

declare(strict_types=1);

namespace App\Filament;

use App\Filament\Pages\CatalogSetup;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ModulePlaceholder;
use App\Filament\Pages\Settings;
use App\Filament\Resources\AccountsPayable\AccountsPayableResource;
use App\Filament\Resources\AccountsReceivable\AccountsReceivableResource;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\ChartOfAccounts\ChartOfAccountResource;
use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\DashboardUsers\DashboardUserResource;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Filament\Resources\EmployeeReports\EmployeeReportResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\FinancialReports\FinancialReportResource;
use App\Filament\Resources\FiscalPeriods\FiscalPeriodResource;
use App\Filament\Resources\InventoryAlerts\InventoryAlertResource;
use App\Filament\Resources\InventoryImportRuns\InventoryImportRunResource;
use App\Filament\Resources\InventoryLots\InventoryLotResource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\InventoryReports\InventoryReportResource;
use App\Filament\Resources\InventorySettings\InventorySettingResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Resources\MonthlyPlans\MonthlyPlanResource;
use App\Filament\Resources\OperationalReports\OperationalReportResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\PackageTypes\PackageTypeResource;
use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\PaymentTerms\PaymentTermResource;
use App\Filament\Resources\Performance\PerformanceResource;
use App\Filament\Resources\PriceFloorOverrides\PriceFloorOverrideResource;
use App\Filament\Resources\PriceHistories\PriceHistoryResource;
use App\Filament\Resources\PricingTiers\PricingTierResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseSettings\PurchaseSettingResource;
use App\Filament\Resources\PurchasingReports\PurchasingReportResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\Refunds\RefundResource;
use App\Filament\Resources\SalaryCalculations\SalaryCalculationResource;
use App\Filament\Resources\SalesOpportunities\SalesOpportunityResource;
use App\Filament\Resources\SerializedInventoryUnits\SerializedInventoryUnitResource;
use App\Filament\Resources\ServiceRecords\ServiceRecordResource;
use App\Filament\Resources\ShipmentAttachments\ShipmentAttachmentResource;
use App\Filament\Resources\SlaPolicies\SlaPolicyResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\SupplierConfirmations\SupplierConfirmationResource;
use App\Filament\Resources\SupplierProductReferences\SupplierProductReferenceResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Resources\SupportReports\SupportReportResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\TaxDefinitions\TaxDefinitionResource;
use App\Filament\Resources\Taxes\TaxResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Filament\Resources\Warehouses\WarehouseResource;
use Filament\Exceptions\NoDefaultPanelSetException;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Single source of truth for the IERP admin domains.
 *
 * Provides the ordered list of navigation groups (label, icon, sort) and the
 * simple Resource/Page links registered in the panel's sidebar navigation.
 *
 * This class must stay free of database queries, permission rules, record
 * counts, and business calculations. Link availability is resolved purely
 * from whether the referenced class exists and is currently accessible.
 *
 * When a real Resource or Page is added for one of the items below, it
 * should declare `$navigationGroup` matching the group's translation key
 * (see {@see self::groups()}) and a `$navigationSort` in the group's
 * reserved range (group position * 100, e.g. Sales = 100-199).
 *
 * @phpstan-type ModuleItem array{label: string, link: string, page?: string, section?: string}
 * @phpstan-type ModuleSection array{key: string, label: string}
 * @phpstan-type ModuleGroup array{key: string, label: string, icon: Heroicon, sort: int, items: list<ModuleItem>, sections?: list<ModuleSection>}
 */
final class AdminModuleRegistry
{
    /**
     * @return list<ModuleGroup>
     */
    public static function groups(): array
    {
        return [
            [
                'key' => 'sales',
                'label' => 'admin.groups.sales',
                'icon' => Heroicon::OutlinedShoppingCart,
                'sort' => 1,
                'items' => [
                    ['label' => 'admin.resources.quotations', 'link' => QuotationResource::class],
                    ['label' => 'admin.resources.orders', 'link' => OrderResource::class],
                    ['label' => 'admin.resources.delivery_notes', 'link' => DeliveryNoteResource::class],
                    ['label' => 'admin.resources.invoices', 'link' => InvoiceResource::class],
                    ['label' => 'admin.resources.payments', 'link' => PaymentResource::class],
                    ['label' => 'admin.resources.credit_notes', 'link' => CreditNoteResource::class],
                ],
            ],
            [
                'key' => 'accounting',
                'label' => 'admin.groups.accounting',
                'icon' => Heroicon::OutlinedCalculator,
                'sort' => 2,
                'items' => [
                    ['label' => 'admin.resources.chart_of_accounts', 'link' => ChartOfAccountResource::class],
                    ['label' => 'admin.resources.journal_entries', 'link' => JournalEntryResource::class],
                    ['label' => 'admin.resources.fiscal_periods', 'link' => FiscalPeriodResource::class],
                    ['label' => 'admin.resources.accounts_receivable', 'link' => AccountsReceivableResource::class],
                    ['label' => 'admin.resources.accounts_payable', 'link' => AccountsPayableResource::class],
                    ['label' => 'admin.resources.bills', 'link' => BillResource::class],
                    ['label' => 'admin.resources.expenses', 'link' => ExpenseResource::class],
                    ['label' => 'admin.resources.refunds', 'link' => RefundResource::class],
                    ['label' => 'admin.resources.taxes', 'link' => TaxResource::class],
                    ['label' => 'admin.resources.financial_reports', 'link' => FinancialReportResource::class],
                ],
            ],
            [
                'key' => 'inventory',
                'label' => 'admin.groups.inventory',
                'icon' => Heroicon::OutlinedCube,
                'sort' => 3,
                'sections' => [
                    ['key' => 'operations', 'label' => 'admin.sections.operations'],
                    ['key' => 'products', 'label' => 'admin.sections.products'],
                    ['key' => 'reporting', 'label' => 'admin.sections.reporting'],
                    ['key' => 'configurations', 'label' => 'admin.sections.configurations'],
                ],
                'items' => [
                    ['label' => 'admin.resources.inventory_operations', 'link' => InventoryOperationResource::class, 'section' => 'operations'],
                    ['label' => 'admin.resources.shipment_attachments', 'link' => ShipmentAttachmentResource::class, 'section' => 'operations'],
                    ['label' => 'admin.resources.adjustments', 'link' => AdjustmentResource::class, 'section' => 'operations'],
                    ['label' => 'admin.resources.stock_levels', 'link' => StockLevelResource::class, 'section' => 'reporting'],
                    ['label' => 'admin.resources.products', 'link' => ProductResource::class, 'section' => 'products'],
                    ['label' => 'admin.resources.packages', 'link' => PackageResource::class, 'section' => 'products'],
                    ['label' => 'admin.resources.inventory_lots', 'link' => InventoryLotResource::class, 'section' => 'products'],
                    ['label' => 'admin.resources.serialized_inventory_units', 'link' => SerializedInventoryUnitResource::class, 'section' => 'products'],
                    ['label' => 'admin.resources.stock_movements', 'link' => StockMovementResource::class, 'section' => 'reporting'],
                    ['label' => 'admin.resources.inventory_alerts', 'link' => InventoryAlertResource::class, 'section' => 'reporting'],
                    ['label' => 'admin.resources.warehouses', 'link' => WarehouseResource::class, 'section' => 'configurations'],
                    ['label' => 'admin.resources.package_types', 'link' => PackageTypeResource::class, 'section' => 'configurations'],
                    ['label' => 'admin.resources.catalog_setup', 'link' => CatalogSetup::class, 'section' => 'configurations'],
                    ['label' => 'admin.resources.catalog_imports', 'link' => InventoryImportRunResource::class, 'section' => 'configurations'],
                    ['label' => 'admin.resources.inventory_settings', 'link' => InventorySettingResource::class, 'section' => 'configurations'],
                ],
            ],
            [
                'key' => 'purchasing',
                'label' => 'admin.groups.purchasing',
                'icon' => Heroicon::OutlinedTruck,
                'sort' => 4,
                'items' => [
                    ['label' => 'admin.resources.suppliers', 'link' => SupplierResource::class],
                    ['label' => 'admin.resources.purchase_orders', 'link' => PurchaseOrderResource::class],
                    ['label' => 'admin.resources.supplier_confirmations', 'link' => SupplierConfirmationResource::class],
                    ['label' => 'admin.resources.supplier_product_references', 'link' => SupplierProductReferenceResource::class],
                    ['label' => 'admin.resources.purchase_settings', 'link' => PurchaseSettingResource::class],
                ],
            ],
            [
                'key' => 'crm',
                'label' => 'admin.groups.crm',
                'icon' => Heroicon::OutlinedUserGroup,
                'sort' => 5,
                'items' => [
                    ['label' => 'admin.resources.customers', 'link' => CustomerResource::class],
                    ['label' => 'admin.resources.pricing_tiers', 'link' => PricingTierResource::class],
                    ['label' => 'admin.resources.price_histories', 'link' => PriceHistoryResource::class],
                    ['label' => 'admin.resources.price_floor_overrides', 'link' => PriceFloorOverrideResource::class],
                ],
            ],
            [
                'key' => 'employees',
                'label' => 'admin.groups.employees',
                'icon' => Heroicon::OutlinedIdentification,
                'sort' => 6,
                'sections' => [
                    ['key' => 'workforce', 'label' => 'admin.sections.workforce'],
                    ['key' => 'planning', 'label' => 'admin.sections.planning'],
                    ['key' => 'field', 'label' => 'admin.sections.field'],
                    ['key' => 'intelligence', 'label' => 'admin.sections.intelligence'],
                    ['key' => 'compensation', 'label' => 'admin.sections.compensation'],
                ],
                'items' => [
                    ['label' => 'admin.resources.employees', 'link' => EmployeeResource::class, 'section' => 'workforce'],
                    ['label' => 'admin.resources.monthly_plans', 'link' => MonthlyPlanResource::class, 'section' => 'planning'],
                    ['label' => 'admin.resources.tasks', 'link' => TaskResource::class, 'section' => 'planning'],
                    ['label' => 'admin.resources.visits', 'link' => VisitResource::class, 'section' => 'field'],
                    ['label' => 'admin.resources.sales_opportunity', 'link' => SalesOpportunityResource::class, 'section' => 'intelligence'],
                    ['label' => 'admin.resources.performance', 'link' => PerformanceResource::class, 'section' => 'compensation'],
                    ['label' => 'admin.resources.salary_calculations', 'link' => SalaryCalculationResource::class, 'section' => 'compensation'],
                ],
            ],
            [
                'key' => 'support',
                'label' => 'admin.groups.support',
                'icon' => Heroicon::OutlinedWrenchScrewdriver,
                'sort' => 7,
                'items' => [
                    ['label' => 'admin.resources.tickets', 'link' => TicketResource::class],
                    ['label' => 'admin.resources.maintenance_requests', 'link' => MaintenanceRequestResource::class],
                    ['label' => 'admin.resources.service_records', 'link' => ServiceRecordResource::class],
                    ['label' => 'admin.resources.sla_policies', 'link' => SlaPolicyResource::class],
                ],
            ],
            [
                'key' => 'reports',
                'label' => 'admin.groups.reports',
                'icon' => Heroicon::OutlinedDocumentChartBar,
                'sort' => 8,
                'items' => [
                    ['label' => 'admin.resources.inventory_reports', 'link' => InventoryReportResource::class],
                    ['label' => 'admin.resources.operational_reports', 'link' => OperationalReportResource::class],
                    ['label' => 'admin.resources.financial_reports', 'link' => FinancialReportResource::class],
                    ['label' => 'admin.resources.employee_reports', 'link' => EmployeeReportResource::class],
                    ['label' => 'admin.resources.support_reports', 'link' => SupportReportResource::class],
                    ['label' => 'admin.resources.purchasing_reports', 'link' => PurchasingReportResource::class],
                    ['label' => 'admin.resources.audit_logs', 'link' => AuditLogResource::class],
                ],
            ],
            [
                'key' => 'system',
                'label' => 'admin.groups.system',
                'icon' => Heroicon::OutlinedCog6Tooth,
                'sort' => 9,
                'items' => [
                    ['label' => 'admin.resources.payment_terms', 'link' => PaymentTermResource::class],
                    ['label' => 'admin.resources.payment_methods', 'link' => PaymentMethodResource::class],
                    ['label' => 'admin.resources.tax_definitions', 'link' => TaxDefinitionResource::class],
                    ['label' => 'admin.resources.inventory_settings', 'link' => InventorySettingResource::class],
                    ['label' => 'admin.resources.document_templates', 'link' => DocumentTemplateResource::class],
                    ['label' => 'admin.resources.dashboard_users', 'link' => DashboardUserResource::class],
                    ['label' => 'admin.resources.settings', 'link' => Settings::class],
                ],
            ],
        ];
    }

    /**
     * Resolve a Resource/Page class to its index URL, but only when the class
     * exists, is a real Filament Resource or Page, and the current user is
     * authorized to access it. Returns null otherwise so callers never render
     * a broken or unauthorized link.
     */
    public static function resolveLink(string $class): ?string
    {
        if (! class_exists($class)) {
            return null;
        }

        if (! is_subclass_of($class, Resource::class) && ! is_subclass_of($class, Page::class)) {
            return null;
        }

        try {
            if (! $class::canAccess()) {
                return null;
            }

            return $class::getUrl();
        } catch (Throwable) {
            return null;
        }
    }

    public static function resolveResourceRecordLink(string $resource, int $recordId): ?string
    {
        if (! is_subclass_of($resource, Resource::class)) {
            return null;
        }

        try {
            if (! $resource::canAccess()) {
                return null;
            }

            return $resource::getUrl('view', ['record' => $recordId]);
        } catch (Throwable) {
            return null;
        }
    }

    public static function isAccessDenied(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        if (! is_subclass_of($class, Resource::class) && ! is_subclass_of($class, Page::class)) {
            return false;
        }

        try {
            return ! $class::canAccess();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Find a group and item by their sidebar identifiers, as used by the
     * shared {@see ModulePlaceholder} page. Returns null when the
     * combination does not exist, so the page can 404 instead of rendering
     * arbitrary, unvalidated input.
     *
     * @return array{group: ModuleGroup, item: ModuleItem}|null
     */
    public static function findItem(string $groupKey, string $itemSlug): ?array
    {
        foreach (self::groups() as $group) {
            if ($group['key'] !== $groupKey) {
                continue;
            }

            foreach ($group['items'] as $item) {
                if (self::itemSlug($item['label']) === $itemSlug) {
                    return ['group' => $group, 'item' => $item];
                }
            }
        }

        return null;
    }

    /**
     * Determine which module the current request belongs to, so the panel's
     * top bar and sidebar can be scoped to it. Returns null when the request
     * does not belong to any module (e.g. the dashboard).
     *
     * @param  list<ModuleGroup>|null  $groups  Overrides {@see self::groups()}, for testing.
     *
     * @throws NoDefaultPanelSetException
     */
    public static function activeGroupKey(?array $groups = null): ?string
    {
        $route = request()->route();

        if ($route === null) {
            return null;
        }

        $routeName = $route->getName();

        if ($routeName === null) {
            return null;
        }

        if ($routeName === ModulePlaceholder::getRouteName()) {
            return request()->query('group');
        }

        $panelId = Filament::getCurrentOrDefaultPanel()?->getId();

        foreach ($groups ?? self::groups() as $group) {
            foreach ($group['items'] as $item) {
                if (is_subclass_of($item['link'], Resource::class)) {
                    if (Str::startsWith($routeName, sprintf('filament.%s.resources.%s.', $panelId, $item['link']::getSlug()))) {
                        return $group['key'];
                    }

                    continue;
                }

                if (is_subclass_of($item['link'], Page::class) && $routeName === $item['link']::getRouteName()) {
                    return $group['key'];
                }
            }
        }

        return null;
    }

    /**
     * Resolve the URL of the first item in a group that the current user can
     * reach, falling back to that item's placeholder page when none of the
     * group's items resolve to a real Resource or Page yet.
     *
     * @param  ModuleGroup  $group
     */
    public static function firstUrlFor(array $group): string
    {
        $placeholderItem = null;

        foreach ($group['items'] as $item) {
            $link = self::resolveLink($item['link']);

            if ($link !== null) {
                return $link;
            }

            if (self::isAccessDenied($item['link'])) {
                continue;
            }

            $placeholderItem ??= $item;
        }

        if ($placeholderItem === null) {
            return Dashboard::getUrl();
        }

        return ModulePlaceholder::getUrl([
            'group' => $group['key'],
            'item' => self::itemSlug($placeholderItem['label']),
        ]);
    }

    /**
     * Collect the navigation items already registered by the real Resources
     * and Pages backing the given group's items, so the active module's own
     * navigation entries surface alongside the registry's placeholder ones.
     *
     * @param  ModuleGroup  $group
     * @param  string|null  $onlySection  When given, only collects items declaring this section key.
     * @return list<NavigationItem>
     */
    public static function registeredNavigationItemsFor(array $group, ?string $onlySection = null): array
    {
        $items = [];

        foreach ($group['items'] as $item) {
            if ($onlySection !== null && ($item['section'] ?? null) !== $onlySection) {
                continue;
            }

            if (self::resolveLink($item['link']) === null) {
                continue;
            }

            if (isset($item['page']) && is_subclass_of($item['link'], Resource::class)) {
                $resource = $item['link'];
                $page = $item['page'];

                $items[] = NavigationItem::make($item['label'])
                    ->label(fn (): string => __($item['label']))
                    ->url(fn (): string => $resource::getUrl($page))
                    ->isActiveWhen(fn (): bool => request()->routeIs($resource::getRouteBaseName().'.'.$page));

                continue;
            }

            if (is_subclass_of($item['link'], Resource::class) || is_subclass_of($item['link'], Page::class)) {
                $items = [...$items, ...$item['link']::getNavigationItems()];
            }
        }

        return array_values($items);
    }

    /**
     * Build the sidebar navigation items for every module item that does not
     * yet resolve to a real Resource or Page. Items with a working link are
     * skipped here because their own Resource/Page already registers itself
     * in navigation, so we never render a duplicate entry.
     *
     * @param  list<ModuleGroup>|null  $groups  Overrides {@see self::groups()}, for testing.
     * @param  string|null  $onlyGroupKey  When given, only builds items for this group.
     * @param  string|null  $onlySection  When given, only builds items declaring this section key.
     * @return list<NavigationItem>
     */
    public static function navigationItems(?array $groups = null, ?string $onlyGroupKey = null, ?string $onlySection = null): array
    {
        $items = [];

        foreach ($groups ?? self::groups() as $group) {
            if ($onlyGroupKey !== null && $group['key'] !== $onlyGroupKey) {
                continue;
            }

            foreach ($group['items'] as $index => $item) {
                if ($onlySection !== null && ($item['section'] ?? null) !== $onlySection) {
                    continue;
                }

                if (self::isAccessDenied($item['link'])) {
                    continue;
                }

                if (self::resolveLink($item['link']) !== null) {
                    continue;
                }

                $itemSlug = self::itemSlug($item['label']);

                $items[] = NavigationItem::make($item['label'])
                    ->label(fn (): string => __($item['label']))
                    ->group(fn (): string => __($group['label']))
                    ->sort(($group['sort'] * 100) + $index)
                    ->url(fn (): string => ModulePlaceholder::getUrl([
                        'group' => $group['key'],
                        'item' => $itemSlug,
                    ]))
                    ->isActiveWhen(fn (): bool => request()->routeIs(ModulePlaceholder::getRouteName())
                        && request()->query('group') === $group['key']
                        && request()->query('item') === $itemSlug);
            }
        }

        return $items;
    }

    /**
     * Derive a stable, URL-safe identifier for an item from its translation
     * key (e.g. "admin.resources.credit_notes" becomes "credit_notes").
     */
    private static function itemSlug(string $labelKey): string
    {
        return Str::afterLast($labelKey, '.');
    }
}
