<?php

declare(strict_types=1);

namespace App\Filament;

use App\Filament\Pages\ModulePlaceholder;
use App\Filament\Pages\Settings;
use App\Filament\Resources\AccountsPayable\AccountsPayableResource;
use App\Filament\Resources\AccountsReceivable\AccountsReceivableResource;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\ChartOfAccounts\ChartOfAccountResource;
use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Filament\Resources\EmployeeReports\EmployeeReportResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\FinancialReports\FinancialReportResource;
use App\Filament\Resources\InventoryReports\InventoryReportResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Resources\MonthlyPlans\MonthlyPlanResource;
use App\Filament\Resources\OperationalReports\OperationalReportResource;
use App\Filament\Resources\Opportunities\OpportunityResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\PaymentTerms\PaymentTermResource;
use App\Filament\Resources\Performance\PerformanceResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\Refunds\RefundResource;
use App\Filament\Resources\Returns\ReturnResource;
use App\Filament\Resources\SalaryCalculations\SalaryCalculationResource;
use App\Filament\Resources\ServiceRecords\ServiceRecordResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\SupplierConfirmations\SupplierConfirmationResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\TaxDefinitions\TaxDefinitionResource;
use App\Filament\Resources\Taxes\TaxResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Resources\Transfers\TransferResource;
use App\Filament\Resources\Units\UnitResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Filament\Resources\Warehouses\WarehouseResource;
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
 * @phpstan-type ModuleItem array{label: string, link: class-string}
 * @phpstan-type ModuleGroup array{key: string, label: string, icon: Heroicon, sort: int, items: list<ModuleItem>}
 */
class AdminModuleRegistry
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
                'items' => [
                    ['label' => 'admin.resources.products', 'link' => ProductResource::class],
                    ['label' => 'admin.resources.product_variants', 'link' => ProductVariantResource::class],
                    ['label' => 'admin.resources.warehouses', 'link' => WarehouseResource::class],
                    ['label' => 'admin.resources.stock_levels', 'link' => StockLevelResource::class],
                    ['label' => 'admin.resources.stock_movements', 'link' => StockMovementResource::class],
                    ['label' => 'admin.resources.transfers', 'link' => TransferResource::class],
                    ['label' => 'admin.resources.adjustments', 'link' => AdjustmentResource::class],
                    ['label' => 'admin.resources.returns', 'link' => ReturnResource::class],
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
                ],
            ],
            [
                'key' => 'crm',
                'label' => 'admin.groups.crm',
                'icon' => Heroicon::OutlinedUserGroup,
                'sort' => 5,
                'items' => [
                    ['label' => 'admin.resources.customers', 'link' => CustomerResource::class],
                    ['label' => 'admin.resources.leads', 'link' => LeadResource::class],
                    ['label' => 'admin.resources.opportunities', 'link' => OpportunityResource::class],
                    ['label' => 'admin.resources.activities', 'link' => ActivityResource::class],
                    ['label' => 'admin.resources.campaigns', 'link' => CampaignResource::class],
                ],
            ],
            [
                'key' => 'employees',
                'label' => 'admin.groups.employees',
                'icon' => Heroicon::OutlinedIdentification,
                'sort' => 6,
                'items' => [
                    ['label' => 'admin.resources.employees', 'link' => EmployeeResource::class],
                    ['label' => 'admin.resources.monthly_plans', 'link' => MonthlyPlanResource::class],
                    ['label' => 'admin.resources.visits', 'link' => VisitResource::class],
                    ['label' => 'admin.resources.tasks', 'link' => TaskResource::class],
                    ['label' => 'admin.resources.performance', 'link' => PerformanceResource::class],
                    ['label' => 'admin.resources.salary_calculations', 'link' => SalaryCalculationResource::class],
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
                ],
            ],
            [
                'key' => 'reports',
                'label' => 'admin.groups.reports',
                'icon' => Heroicon::OutlinedDocumentChartBar,
                'sort' => 8,
                'items' => [
                    ['label' => 'admin.resources.operational_reports', 'link' => OperationalReportResource::class],
                    ['label' => 'admin.resources.financial_reports', 'link' => FinancialReportResource::class],
                    ['label' => 'admin.resources.inventory_reports', 'link' => InventoryReportResource::class],
                    ['label' => 'admin.resources.employee_reports', 'link' => EmployeeReportResource::class],
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
                    ['label' => 'admin.resources.units', 'link' => UnitResource::class],
                    ['label' => 'admin.resources.document_templates', 'link' => DocumentTemplateResource::class],
                    ['label' => 'admin.resources.users_and_permissions', 'link' => UserResource::class],
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
     *
     * @param  class-string  $class
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
     * Build the sidebar navigation items for every module item that does not
     * yet resolve to a real Resource or Page. Items with a working link are
     * skipped here because their own Resource/Page already registers itself
     * in navigation, so we never render a duplicate entry.
     *
     * @return list<NavigationItem>
     */
    public static function navigationItems(): array
    {
        $items = [];

        foreach (self::groups() as $group) {
            foreach ($group['items'] as $index => $item) {
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
