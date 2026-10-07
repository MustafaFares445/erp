<?php

declare(strict_types=1);

namespace App\Filament;

use App\Filament\Pages\AccountingDashboard;
use App\Filament\Pages\BarcodeWorkbench;
use App\Filament\Pages\CatalogSetup;
use App\Filament\Pages\CrmDashboard;
use App\Filament\Pages\EmployeesDashboard;
use App\Filament\Pages\InventoryDashboard;
use App\Filament\Pages\ModulePlaceholder;
use App\Filament\Pages\PurchaseNeeds;
use App\Filament\Pages\PurchasingDashboard;
use App\Filament\Pages\ReportsCenter;
use App\Filament\Pages\SalesDashboard;
use App\Filament\Pages\SupportDashboard;
use App\Filament\Resources\AccountsPayable\AccountsPayableResource;
use App\Filament\Resources\AccountsReceivable\AccountsReceivableResource;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\BankStatements\BankStatementResource;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\ChartOfAccounts\ChartOfAccountResource;
use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Resources\CrmReports\CrmReportResource;
use App\Filament\Resources\Currencies\CurrencyResource;
use App\Filament\Resources\CustomerQuotationRequests\CustomerQuotationRequestResource;
use App\Filament\Resources\CustomerReturnRequests\CustomerReturnRequestResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\CustomFieldDefinitions\CustomFieldDefinitionResource;
use App\Filament\Resources\DashboardUsers\DashboardUserResource;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Filament\Resources\EmployeeReports\EmployeeReportResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\FinancialReports\FinancialReportResource;
use App\Filament\Resources\FiscalPeriods\FiscalPeriodResource;
use App\Filament\Resources\Interactions\InteractionResource;
use App\Filament\Resources\InventoryAlerts\InventoryAlertResource;
use App\Filament\Resources\InventoryConditionChanges\InventoryConditionChangeResource;
use App\Filament\Resources\InventoryCorrections\InventoryCorrectionResource;
use App\Filament\Resources\InventoryCounts\InventoryCountResource;
use App\Filament\Resources\InventoryImportRuns\InventoryImportRunResource;
use App\Filament\Resources\InventoryLots\InventoryLotResource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\InventoryReports\InventoryReportResource;
use App\Filament\Resources\InventoryReservations\InventoryReservationResource;
use App\Filament\Resources\InventorySettings\InventorySettingResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Resources\KnowledgeArticleCategories\KnowledgeArticleCategoryResource;
use App\Filament\Resources\KnowledgeArticles\KnowledgeArticleResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Resources\MaintenanceSchedules\MaintenanceScheduleResource;
use App\Filament\Resources\MonthlyPlans\MonthlyPlanResource;
use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\OutboundFulfillments\OutboundFulfillmentResource;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\PackageTypes\PackageTypeResource;
use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\PaymentTerms\PaymentTermResource;
use App\Filament\Resources\PaymentTransactions\PaymentTransactionResource;
use App\Filament\Resources\Performance\PerformanceResource;
use App\Filament\Resources\PriceFloorOverrides\PriceFloorOverrideResource;
use App\Filament\Resources\PriceHistories\PriceHistoryResource;
use App\Filament\Resources\PricingTiers\PricingTierResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Filament\Resources\PurchaseAgreements\PurchaseAgreementResource;
use App\Filament\Resources\PurchaseInbounds\PurchaseInboundResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseRfqs\PurchaseRfqResource;
use App\Filament\Resources\PurchaseSettings\PurchaseSettingResource;
use App\Filament\Resources\PurchasingReports\PurchasingReportResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\ReceivableWriteOffs\ReceivableWriteOffResource;
use App\Filament\Resources\Refunds\RefundResource;
use App\Filament\Resources\Returns\ReturnResource;
use App\Filament\Resources\SalaryCalculations\SalaryCalculationResource;
use App\Filament\Resources\SalesOpportunities\SalesOpportunityResource;
use App\Filament\Resources\SalesReports\SalesReportResource;
use App\Filament\Resources\SalesSettings\SalesSettingResource;
use App\Filament\Resources\SerializedInventoryUnits\SerializedInventoryUnitResource;
use App\Filament\Resources\ServiceAppointments\ServiceAppointmentResource;
use App\Filament\Resources\ServiceRecords\ServiceRecordResource;
use App\Filament\Resources\Shipments\ShipmentResource;
use App\Filament\Resources\SlaCalendars\SlaCalendarResource;
use App\Filament\Resources\SlaPolicies\SlaPolicyResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\SupplierConfirmations\SupplierConfirmationResource;
use App\Filament\Resources\SupplierPayments\SupplierPaymentResource;
use App\Filament\Resources\SupplierProductReferences\SupplierProductReferenceResource;
use App\Filament\Resources\SupplierProductSupports\SupplierProductSupportResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Resources\SupportAutomationRules\SupportAutomationRuleResource;
use App\Filament\Resources\SupportEntitlements\SupportEntitlementResource;
use App\Filament\Resources\SupportEquipment\SupportEquipmentResource;
use App\Filament\Resources\SupportQueues\SupportQueueResource;
use App\Filament\Resources\SupportReports\SupportReportResource;
use App\Filament\Resources\SupportRoutingRules\SupportRoutingRuleResource;
use App\Filament\Resources\SupportServiceLevels\SupportServiceLevelResource;
use App\Filament\Resources\SupportSkills\SupportSkillResource;
use App\Filament\Resources\SupportTeams\SupportTeamResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Taxes\TaxResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Filament\Resources\WarehouseReplenishmentPolicies\WarehouseReplenishmentPolicyResource;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Filament\Resources\WarrantyPolicies\WarrantyPolicyResource;
use App\Filament\Support\WorkspaceNavigation;
use Closure;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;
use WeakMap;

/**
 * Single source of truth for the IERP admin domains.
 *
 * A group item is either a direct link or a workspace: an item with `tabs` is one sidebar destination
 * that fans out into several existing resources (see {@see WorkspaceNavigation}). A workspace's `link`
 * is its default tab, `tools` are workspace-wide shortcuts shown beside the tabs, and a group's
 * `contextual` classes belong to the module without having a sidebar entry of their own.
 *
 * @phpstan-type WorkspaceTab array{label: string, link: class-string<resource>, page?: string}
 * @phpstan-type WorkspaceTool array{label: string, link: string, icon: Heroicon, filters?: array<string, array<string, mixed>>}
 * @phpstan-type ModuleItem array{label: string, link: string, page?: string, section?: string, icon?: Heroicon, tabs?: list<WorkspaceTab>, tools?: list<WorkspaceTool>}
 * @phpstan-type ModuleSection array{key: string, label: string}
 * @phpstan-type ModuleGroup array{key: string, label: string, icon: Heroicon, sort: int, items: list<ModuleItem>, sections?: list<ModuleSection>, contextual?: list<class-string<resource|page>>}
 */
final class AdminModuleRegistry
{
    /** @var WeakMap<Request, array<string, mixed>>|null */
    private static ?WeakMap $requestMemo = null;

    /**
     * Request-scoped memoization for navigation facts that cannot change while one HTTP request is
     * being served (what the signed-in user may open, and where each module lands).
     *
     * Entries hang off the current Request object, so they vanish with it and can never leak into
     * another request, and they are additionally keyed by the authenticated user so a permission
     * result is never shared between users.
     *
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    private static function remember(string $key, Closure $compute): mixed
    {
        $request = request();
        $key = (Auth::id() ?? 'guest').'|'.$key;
        $memo = self::$requestMemo ??= new WeakMap;

        $bucket = $memo[$request] ?? [];

        if (! array_key_exists($key, $bucket)) {
            $value = $compute();
            $bucket = $memo[$request] ?? [];
            $bucket[$key] = $value;
            $memo[$request] = $bucket;
        }

        return $bucket[$key];
    }

    /** Drops every memoized navigation fact (for code that changes access mid-request). */
    public static function forgetMemoized(): void
    {
        self::$requestMemo = null;
    }

    /** @var list<ModuleGroup>|null */
    private static ?array $groupDefinitions = null;

    /**
     * The module definitions are static configuration (no user or request input), so they are
     * built once per process rather than on each of the many calls a single page render makes.
     *
     * @return list<ModuleGroup>
     */
    public static function groups(): array
    {
        return self::$groupDefinitions ??= self::buildGroups();
    }

    /** @return list<ModuleGroup> */
    private static function buildGroups(): array
    {
        return [
            [
                'key' => 'sales',
                'label' => 'admin.groups.sales',
                'icon' => Heroicon::OutlinedShoppingCart,
                'sort' => 1,
                'sections' => [
                    ['key' => 'overview', 'label' => 'admin.sections.overview'],
                    ['key' => 'selling', 'label' => 'admin.sections.selling'],
                    ['key' => 'fulfillment', 'label' => 'admin.sections.fulfillment'],
                    ['key' => 'billing', 'label' => 'admin.sections.billing'],
                    ['key' => 'reports', 'label' => 'admin.sections.reports'],
                ],
                'items' => [
                    ['label' => 'admin.resources.sales_dashboard', 'link' => SalesDashboard::class, 'section' => 'overview'],
                    ['label' => 'admin.resources.quotations', 'link' => QuotationResource::class, 'section' => 'selling'],
                    ['label' => 'admin.resources.orders', 'link' => OrderResource::class, 'section' => 'selling'],
                    ['label' => 'admin.resources.delivery_notes', 'link' => DeliveryNoteResource::class, 'section' => 'fulfillment'],
                    ['label' => 'admin.resources.invoices', 'link' => InvoiceResource::class, 'section' => 'billing'],
                    ['label' => 'admin.resources.payments', 'link' => PaymentResource::class, 'section' => 'billing'],
                    ['label' => 'admin.resources.payment_transactions', 'link' => PaymentTransactionResource::class, 'section' => 'billing'],
                    ['label' => 'admin.resources.credit_notes', 'link' => CreditNoteResource::class, 'section' => 'billing'],
                    ['label' => 'admin.resources.sales_reports', 'link' => SalesReportResource::class, 'section' => 'reports'],
                ],
            ],
            [
                'key' => 'accounting',
                'label' => 'admin.groups.accounting',
                'icon' => Heroicon::OutlinedCalculator,
                'sort' => 2,
                'sections' => [
                    ['key' => 'overview', 'label' => 'admin.sections.overview'],
                    ['key' => 'ledger', 'label' => 'admin.sections.ledger'],
                    ['key' => 'receivables', 'label' => 'admin.sections.receivables'],
                    ['key' => 'payables', 'label' => 'admin.sections.payables'],
                    ['key' => 'expenses_taxes', 'label' => 'admin.sections.expenses_taxes'],
                    ['key' => 'reports', 'label' => 'admin.sections.reports'],
                    ['key' => 'setup', 'label' => 'admin.sections.setup'],
                ],
                'items' => [
                    ['label' => 'admin.resources.accounting_dashboard', 'link' => AccountingDashboard::class, 'section' => 'overview'],
                    ['label' => 'admin.resources.chart_of_accounts', 'link' => ChartOfAccountResource::class, 'section' => 'ledger'],
                    ['label' => 'admin.resources.journal_entries', 'link' => JournalEntryResource::class, 'section' => 'ledger'],
                    ['label' => 'admin.resources.bank_statements', 'link' => BankStatementResource::class, 'section' => 'ledger'],
                    ['label' => 'admin.resources.fiscal_periods', 'link' => FiscalPeriodResource::class, 'section' => 'ledger'],
                    ['label' => 'admin.resources.accounts_receivable', 'link' => AccountsReceivableResource::class, 'section' => 'receivables'],
                    ['label' => 'admin.resources.accounts_payable', 'link' => AccountsPayableResource::class, 'section' => 'payables'],
                    ['label' => 'admin.resources.bills', 'link' => BillResource::class, 'section' => 'payables'],
                    ['label' => 'admin.resources.supplier_payments', 'link' => SupplierPaymentResource::class, 'page' => 'index', 'icon' => Heroicon::OutlinedBanknotes, 'section' => 'payables'],
                    ['label' => 'admin.resources.expenses', 'link' => ExpenseResource::class, 'section' => 'expenses_taxes'],
                    ['label' => 'admin.resources.refunds', 'link' => RefundResource::class, 'section' => 'receivables'],
                    ['label' => 'admin.resources.taxes', 'link' => TaxResource::class, 'section' => 'expenses_taxes'],
                    ['label' => 'admin.resources.financial_reports', 'link' => FinancialReportResource::class, 'section' => 'reports'],
                    [
                        'label' => 'admin.sections.accounting_setup',
                        'section' => 'setup',
                        'link' => CurrencyResource::class,
                        'icon' => Heroicon::OutlinedCog6Tooth,
                        'tabs' => [
                            ['label' => 'admin.resources.currencies', 'link' => CurrencyResource::class],
                            ['label' => 'admin.resources.payment_terms', 'link' => PaymentTermResource::class],
                            ['label' => 'admin.resources.payment_methods', 'link' => PaymentMethodResource::class],
                            ['label' => 'admin.resources.tax_definitions', 'link' => SalesSettingResource::class],
                        ],
                    ],
                ],
                'contextual' => [
                    ReceivableWriteOffResource::class,
                ],
            ],
            [
                'key' => 'inventory',
                'label' => 'admin.groups.inventory',
                'icon' => Heroicon::OutlinedCube,
                'sort' => 3,
                'sections' => [
                    ['key' => 'overview', 'label' => 'admin.sections.overview'],
                    ['key' => 'stock', 'label' => 'admin.sections.stock'],
                    ['key' => 'operations', 'label' => 'admin.sections.operations'],
                    ['key' => 'planning', 'label' => 'admin.sections.planning'],
                    ['key' => 'reports', 'label' => 'admin.sections.reports'],
                    ['key' => 'setup', 'label' => 'admin.sections.setup'],
                ],
                'items' => [
                    ['label' => 'admin.resources.inventory_dashboard', 'link' => InventoryDashboard::class, 'section' => 'overview'],
                    [
                        'label' => 'admin.sections.stock',
                        'section' => 'stock',
                        'link' => StockLevelResource::class,
                        'icon' => Heroicon::OutlinedChartBarSquare,
                        'tabs' => [
                            ['label' => 'admin.resources.stock_levels', 'link' => StockLevelResource::class],
                            ['label' => 'admin.resources.products', 'link' => ProductResource::class],
                            ['label' => 'admin.resources.inventory_lots', 'link' => InventoryLotResource::class],
                            ['label' => 'admin.resources.serialized_inventory_units', 'link' => SerializedInventoryUnitResource::class],
                            ['label' => 'admin.resources.stock_movements', 'link' => StockMovementResource::class],
                        ],
                        'tools' => [
                            ['label' => 'admin.resources.catalog_imports', 'link' => InventoryImportRunResource::class, 'icon' => Heroicon::OutlinedDocumentArrowUp],
                            ['label' => 'admin.resources.inventory_reports', 'link' => InventoryReportResource::class, 'icon' => Heroicon::OutlinedDocumentChartBar],
                        ],
                    ],
                    [
                        'label' => 'admin.sections.inbound',
                        'section' => 'operations',
                        'link' => PurchaseInboundResource::class,
                        'icon' => Heroicon::OutlinedInboxArrowDown,
                        'tabs' => [
                            ['label' => 'admin.resources.purchase_inbounds', 'link' => PurchaseInboundResource::class],
                            ['label' => 'admin.resources.inventory_receipts_menu', 'link' => InventoryOperationResource::class, 'page' => 'receipts'],
                        ],
                        'tools' => [
                            ['label' => 'admin.resources.barcode_workbench', 'link' => BarcodeWorkbench::class, 'icon' => Heroicon::OutlinedQrCode],
                        ],
                    ],
                    [
                        'label' => 'admin.sections.outbound',
                        'section' => 'operations',
                        'link' => OutboundFulfillmentResource::class,
                        'icon' => Heroicon::OutlinedArrowUpTray,
                        'tabs' => [
                            ['label' => 'admin.resources.outbound_fulfillment', 'link' => OutboundFulfillmentResource::class],
                            ['label' => 'admin.resources.inventory_deliveries', 'link' => InventoryOperationResource::class, 'page' => 'deliveries'],
                            ['label' => 'admin.resources.shipments', 'link' => ShipmentResource::class],
                            ['label' => 'admin.resources.packages', 'link' => PackageResource::class],
                        ],
                        'tools' => [
                            ['label' => 'admin.resources.barcode_workbench', 'link' => BarcodeWorkbench::class, 'icon' => Heroicon::OutlinedQrCode],
                        ],
                    ],
                    [
                        'label' => 'admin.sections.operations',
                        'section' => 'operations',
                        'link' => InventoryOperationResource::class,
                        'icon' => Heroicon::OutlinedArrowsRightLeft,
                        'tabs' => [
                            ['label' => 'admin.resources.internal_transfers', 'link' => InventoryOperationResource::class, 'page' => 'transfers'],
                            ['label' => 'admin.resources.adjustments', 'link' => AdjustmentResource::class],
                            ['label' => 'admin.resources.inventory_counts', 'link' => InventoryCountResource::class],
                            ['label' => 'admin.resources.returns', 'link' => ReturnResource::class],
                            ['label' => 'admin.inventory.correction.resource_label_plural', 'link' => InventoryCorrectionResource::class],
                            ['label' => 'admin.resources.inventory_condition_changes', 'link' => InventoryConditionChangeResource::class],
                            ['label' => 'admin.resources.reservations', 'link' => InventoryReservationResource::class],
                        ],
                        'tools' => [
                            ['label' => 'admin.resources.barcode_workbench', 'link' => BarcodeWorkbench::class, 'icon' => Heroicon::OutlinedQrCode],
                        ],
                    ],
                    [
                        'label' => 'admin.sections.planning_alerts',
                        'section' => 'planning',
                        'link' => InventoryAlertResource::class,
                        'icon' => Heroicon::OutlinedBellAlert,
                        'tabs' => [
                            ['label' => 'admin.resources.inventory_alerts', 'link' => InventoryAlertResource::class],
                            ['label' => 'admin.resources.replenishment_policies', 'link' => WarehouseReplenishmentPolicyResource::class],
                        ],
                        'tools' => [
                            ['label' => 'admin.inventory.stock.low_stock', 'link' => StockLevelResource::class, 'icon' => Heroicon::OutlinedExclamationTriangle, 'filters' => ['low_stock' => ['isActive' => true]]],
                            ['label' => 'admin.resources.inventory_reports', 'link' => InventoryReportResource::class, 'icon' => Heroicon::OutlinedDocumentChartBar],
                        ],
                    ],
                    ['label' => 'admin.resources.inventory_reports', 'link' => InventoryReportResource::class, 'section' => 'reports'],
                    ['label' => 'admin.resources.warehouses', 'link' => WarehouseResource::class, 'section' => 'setup'],
                    ['label' => 'admin.resources.catalog_setup', 'link' => CatalogSetup::class, 'icon' => Heroicon::OutlinedWrenchScrewdriver, 'section' => 'setup'],
                    [
                        'label' => 'admin.sections.inventory_setup',
                        'section' => 'setup',
                        'link' => PackageTypeResource::class,
                        'icon' => Heroicon::OutlinedCog6Tooth,
                        'tabs' => [
                            ['label' => 'admin.resources.package_types', 'link' => PackageTypeResource::class],
                            ['label' => 'admin.resources.inventory_settings', 'link' => InventorySettingResource::class],
                        ],
                    ],
                ],
                'contextual' => [
                    ProductVariantResource::class,
                    InventoryImportRunResource::class,
                    BarcodeWorkbench::class,
                ],
            ],
            [
                'key' => 'vendors',
                'label' => 'admin.groups.vendors',
                'icon' => Heroicon::OutlinedShoppingBag,
                'sort' => 4,
                'sections' => [
                    ['key' => 'overview', 'label' => 'admin.sections.overview'],
                    ['key' => 'planning', 'label' => 'admin.sections.planning'],
                    ['key' => 'suppliers', 'label' => 'admin.sections.suppliers'],
                    ['key' => 'catalog', 'label' => 'admin.sections.catalog'],
                    ['key' => 'reports', 'label' => 'admin.sections.reports'],
                    ['key' => 'setup', 'label' => 'admin.sections.setup'],
                ],
                'items' => [
                    ['label' => 'admin.resources.purchasing_dashboard', 'link' => PurchasingDashboard::class, 'section' => 'overview'],
                    ['label' => 'admin.resources.purchase_needs', 'link' => PurchaseNeeds::class, 'section' => 'planning'],
                    ['label' => 'admin.resources.purchase_rfqs', 'link' => PurchaseRfqResource::class, 'section' => 'planning'],
                    ['label' => 'admin.resources.purchase_agreements', 'link' => PurchaseAgreementResource::class, 'section' => 'planning'],
                    ['label' => 'admin.resources.purchase_orders', 'link' => PurchaseOrderResource::class, 'section' => 'planning'],
                    ['label' => 'admin.resources.suppliers', 'link' => SupplierResource::class, 'section' => 'suppliers'],
                    ['label' => 'admin.resources.supplier_product_references', 'link' => SupplierProductReferenceResource::class, 'section' => 'catalog'],
                    ['label' => 'admin.resources.supplier_product_supports', 'link' => SupplierProductSupportResource::class, 'section' => 'catalog'],
                    ['label' => 'admin.resources.purchasing_reports', 'link' => PurchasingReportResource::class, 'section' => 'reports'],
                    ['label' => 'admin.resources.purchase_settings', 'link' => PurchaseSettingResource::class, 'section' => 'setup'],
                ],
                'contextual' => [
                    SupplierConfirmationResource::class,
                ],
            ],
            [
                'key' => 'crm',
                'label' => 'admin.groups.crm',
                'icon' => Heroicon::OutlinedUserGroup,
                'sort' => 5,
                'sections' => [
                    ['key' => 'overview', 'label' => 'admin.sections.overview'],
                    ['key' => 'customers', 'label' => 'admin.sections.customers'],
                    ['key' => 'pipeline', 'label' => 'admin.sections.pipeline'],
                    ['key' => 'pricing', 'label' => 'admin.sections.pricing'],
                    ['key' => 'reports', 'label' => 'admin.sections.reports'],
                ],
                'items' => [
                    ['label' => 'admin.resources.crm_dashboard', 'link' => CrmDashboard::class, 'section' => 'overview'],
                    ['label' => 'admin.resources.customers', 'link' => CustomerResource::class, 'section' => 'customers'],
                    ['label' => 'admin.resources.customer_quotation_requests', 'link' => CustomerQuotationRequestResource::class, 'section' => 'customers'],
                    ['label' => 'admin.resources.customer_return_requests', 'link' => CustomerReturnRequestResource::class, 'section' => 'customers'],
                    ['label' => 'admin.resources.leads', 'link' => LeadResource::class, 'section' => 'pipeline'],
                    ['label' => 'admin.resources.sales_opportunity', 'link' => SalesOpportunityResource::class, 'section' => 'pipeline'],
                    ['label' => 'admin.resources.interactions', 'link' => InteractionResource::class, 'section' => 'pipeline'],
                    ['label' => 'admin.resources.campaigns', 'link' => CampaignResource::class, 'section' => 'pipeline'],
                    ['label' => 'admin.resources.crm_reports', 'link' => CrmReportResource::class, 'section' => 'reports'],
                    ['label' => 'admin.resources.pricing_tiers', 'link' => PricingTierResource::class, 'section' => 'pricing'],
                    ['label' => 'admin.resources.price_histories', 'link' => PriceHistoryResource::class, 'section' => 'pricing'],
                    ['label' => 'admin.resources.price_floor_overrides', 'link' => PriceFloorOverrideResource::class, 'section' => 'pricing'],
                ],
            ],
            [
                'key' => 'employees',
                'label' => 'admin.groups.employees',
                'icon' => Heroicon::OutlinedIdentification,
                'sort' => 6,
                'sections' => [
                    ['key' => 'overview', 'label' => 'admin.sections.overview'],
                    ['key' => 'workforce', 'label' => 'admin.sections.workforce'],
                    ['key' => 'planning', 'label' => 'admin.sections.planning'],
                    ['key' => 'field', 'label' => 'admin.sections.field'],
                    ['key' => 'compensation', 'label' => 'admin.sections.compensation'],
                    ['key' => 'reports', 'label' => 'admin.sections.reports'],
                ],
                'items' => [
                    ['label' => 'admin.resources.employees_dashboard', 'link' => EmployeesDashboard::class, 'section' => 'overview'],
                    ['label' => 'admin.resources.employees', 'link' => EmployeeResource::class, 'section' => 'workforce'],
                    ['label' => 'admin.resources.monthly_plans', 'link' => MonthlyPlanResource::class, 'section' => 'planning'],
                    ['label' => 'admin.resources.tasks', 'link' => TaskResource::class, 'section' => 'planning'],
                    ['label' => 'admin.resources.visits', 'link' => VisitResource::class, 'section' => 'field'],
                    ['label' => 'admin.resources.performance', 'link' => PerformanceResource::class, 'section' => 'compensation'],
                    ['label' => 'admin.resources.salary_calculations', 'link' => SalaryCalculationResource::class, 'section' => 'compensation'],
                    ['label' => 'admin.resources.employee_reports', 'link' => EmployeeReportResource::class, 'section' => 'reports'],
                ],
            ],
            [
                'key' => 'support',
                'label' => 'admin.groups.support',
                'icon' => Heroicon::OutlinedWrenchScrewdriver,
                'sort' => 7,
                'sections' => [
                    ['key' => 'overview', 'label' => 'admin.sections.overview'],
                    ['key' => 'service_desk', 'label' => 'admin.sections.service_desk'],
                    ['key' => 'field_service', 'label' => 'admin.sections.field_service'],
                    ['key' => 'reports', 'label' => 'admin.sections.reports'],
                    ['key' => 'configuration', 'label' => 'admin.sections.configuration'],
                ],
                'items' => [
                    ['label' => 'admin.resources.support_dashboard', 'link' => SupportDashboard::class, 'section' => 'overview'],
                    ['label' => 'admin.resources.tickets', 'link' => TicketResource::class, 'section' => 'service_desk'],
                    ['label' => 'admin.resources.maintenance_requests', 'link' => MaintenanceRequestResource::class, 'section' => 'service_desk'],
                    ['label' => 'admin.resources.service_records', 'link' => ServiceRecordResource::class, 'section' => 'service_desk'],
                    ['label' => 'admin.resources.maintenance_schedules', 'link' => MaintenanceScheduleResource::class, 'section' => 'field_service'],
                    ['label' => 'admin.resources.field_service', 'link' => ServiceAppointmentResource::class, 'section' => 'field_service'],
                    ['label' => 'admin.resources.equipment_360', 'link' => SupportEquipmentResource::class, 'section' => 'field_service'],
                    ['label' => 'admin.resources.support_reports', 'link' => SupportReportResource::class, 'section' => 'reports'],
                    [
                        'label' => 'admin.sections.service_policies',
                        'section' => 'configuration',
                        'link' => SlaPolicyResource::class,
                        'icon' => Heroicon::OutlinedShieldCheck,
                        'tabs' => [
                            ['label' => 'admin.resources.sla_policies', 'link' => SlaPolicyResource::class],
                            ['label' => 'admin.resources.sla_calendars', 'link' => SlaCalendarResource::class],
                            ['label' => 'admin.resources.support_service_levels', 'link' => SupportServiceLevelResource::class],
                            ['label' => 'admin.resources.support_entitlements', 'link' => SupportEntitlementResource::class],
                            ['label' => 'admin.resources.warranty_policies', 'link' => WarrantyPolicyResource::class],
                        ],
                    ],
                    [
                        'label' => 'admin.sections.advanced_support',
                        'section' => 'configuration',
                        'link' => SupportTeamResource::class,
                        'icon' => Heroicon::OutlinedAdjustmentsHorizontal,
                        'tabs' => [
                            ['label' => 'admin.resources.support_teams', 'link' => SupportTeamResource::class],
                            ['label' => 'admin.resources.support_skills', 'link' => SupportSkillResource::class],
                            ['label' => 'admin.resources.support_queues', 'link' => SupportQueueResource::class],
                            ['label' => 'admin.resources.support_routing_rules', 'link' => SupportRoutingRuleResource::class],
                            ['label' => 'admin.resources.support_automation_rules', 'link' => SupportAutomationRuleResource::class],
                            ['label' => 'admin.resources.knowledge_articles', 'link' => KnowledgeArticleResource::class],
                            ['label' => 'admin.resources.knowledge_categories', 'link' => KnowledgeArticleCategoryResource::class],
                        ],
                    ],
                ],
            ],
            [
                'key' => 'reports',
                'label' => 'admin.groups.reports',
                'icon' => Heroicon::OutlinedDocumentChartBar,
                'sort' => 8,
                'sections' => [
                    ['key' => 'overview', 'label' => 'admin.sections.overview'],
                    ['key' => 'audit', 'label' => 'admin.sections.audit'],
                ],
                'items' => [
                    ['label' => 'reporting.center.navigation', 'link' => ReportsCenter::class, 'section' => 'overview'],
                    ['label' => 'admin.resources.audit_logs', 'link' => AuditLogResource::class, 'section' => 'audit'],
                ],
            ],
            [
                'key' => 'system',
                'label' => 'admin.groups.system',
                'icon' => Heroicon::OutlinedCog6Tooth,
                'sort' => 9,
                'sections' => [
                    ['key' => 'access', 'label' => 'admin.sections.access'],
                    ['key' => 'templates', 'label' => 'admin.sections.templates'],
                    ['key' => 'configurations', 'label' => 'admin.sections.configurations'],
                ],
                'items' => [
                    ['label' => 'admin.resources.dashboard_users', 'link' => DashboardUserResource::class, 'section' => 'access'],
                    ['label' => 'admin.resources.document_templates', 'link' => DocumentTemplateResource::class, 'section' => 'templates'],
                    ['label' => 'admin.resources.notification_templates', 'link' => NotificationTemplateResource::class, 'section' => 'templates'],
                    ['label' => 'admin.resources.custom_fields', 'link' => CustomFieldDefinitionResource::class, 'section' => 'configurations'],
                ],
            ],
        ];
    }

    /**
     * Resources intentionally kept off the module sidebar because their normal entry point is contextual:
     * a group's declared `contextual` classes plus every workspace tab that is not itself a sidebar link.
     *
     * @return list<class-string<resource>>
     */
    public static function contextualResources(): array
    {
        $direct = [];
        $contextual = [];
        foreach (self::groups() as $group) {
            foreach ($group['items'] as $item) {
                $direct[] = $item['link'];
                foreach ($item['tabs'] ?? [] as $tab) {
                    $contextual[] = $tab['link'];
                }
            }
            array_push($contextual, ...($group['contextual'] ?? []));
        }

        return array_values(array_unique(array_filter(
            $contextual,
            static fn (string $class): bool => is_subclass_of($class, Resource::class) && ! in_array($class, $direct, true),
        )));
    }

    /**
     * Every class that belongs to a module without necessarily being a visible sidebar link: the
     * direct items, the tabs of each workspace item, and the group's declared contextual classes.
     *
     * @param  ModuleGroup  $group
     * @return list<string>
     */
    public static function memberClassesOf(array $group): array
    {
        $members = [];
        foreach ($group['items'] as $item) {
            $members[] = $item['link'];
            foreach ($item['tabs'] ?? [] as $tab) {
                $members[] = $tab['link'];
            }
        }

        return array_values(array_unique([...$members, ...($group['contextual'] ?? [])]));
    }

    public static function resolveLink(string $class): ?string
    {
        return self::remember('link|'.$class, static fn (): ?string => self::computeLink($class));
    }

    private static function computeLink(string $class): ?string
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
        return self::remember('denied|'.$class, static fn (): bool => self::computeAccessDenied($class));
    }

    private static function computeAccessDenied(string $class): bool
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

    /** @return array{group: ModuleGroup, item: ModuleItem}|null */
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

    /** @param list<ModuleGroup>|null $groups */
    public static function activeGroupKey(?array $groups = null): ?string
    {
        if ($groups !== null) {
            return self::computeActiveGroupKey($groups);
        }

        return self::remember(
            'active-group',
            static fn (): ?string => self::computeActiveGroupKey(self::groups()),
        );
    }

    /**
     * @param  list<ModuleGroup>  $groups
     */
    private static function computeActiveGroupKey(array $groups): ?string
    {
        $request = request();
        $route = $request->route();
        if ($route === null) {
            return null;
        }
        $routeName = $route->getName();
        if ($routeName === null) {
            return null;
        }
        $activeGroupKey = self::groupKeyForRoute($routeName, $groups, $request);
        if ($activeGroupKey !== null) {
            return $activeGroupKey;
        }
        if (! self::isLivewireRequest($request, $routeName)) {
            return null;
        }

        return self::groupKeyFromLivewireReferer($groups, $request);
    }

    /**
     * Livewire component updates are posted to a generic Livewire route rather than the Filament
     * resource/page route that is visible in the browser. Resolve the module against an explicit
     * request so normal page requests and Livewire referer requests share exactly the same rules.
     *
     * @param  list<ModuleGroup>  $groups
     */
    private static function groupKeyForRoute(string $routeName, array $groups, Request $request): ?string
    {
        if ($routeName === ModulePlaceholder::getRouteName()) {
            $groupKey = $request->query('group');

            return is_string($groupKey) ? $groupKey : null;
        }
        $panelId = Filament::getCurrentOrDefaultPanel()?->getId();
        foreach ($groups as $group) {
            foreach (self::memberClassesOf($group) as $class) {
                if (is_subclass_of($class, Resource::class)) {
                    if (Str::startsWith($routeName, sprintf('filament.%s.resources.%s.', $panelId, $class::getSlug()))) {
                        return $group['key'];
                    }

                    continue;
                }
                if (is_subclass_of($class, Page::class) && $routeName === $class::getRouteName()) {
                    return $group['key'];
                }
            }
        }

        return null;
    }

    private static function isLivewireRequest(Request $request, string $routeName): bool
    {
        if (Str::startsWith($routeName, 'livewire.')) {
            return true;
        }
        if ($request->headers->has('X-Livewire')) {
            return true;
        }

        return $request->is('livewire/*');
    }

    /**
     * Keep the module sidebar scoped during Livewire updates. Without this fallback the active
     * group becomes null on /livewire/update and Filament rebuilds the sidebar with every module.
     *
     * @param  list<ModuleGroup>  $groups
     */
    private static function groupKeyFromLivewireReferer(array $groups, Request $request): ?string
    {
        $referer = $request->headers->get('referer');
        if (! is_string($referer) || $referer === '') {
            return null;
        }
        $refererHost = parse_url($referer, PHP_URL_HOST);
        if (is_string($refererHost) && $refererHost !== '' && ! hash_equals($request->getHost(), $refererHost)) {
            return null;
        }
        try {
            $refererRequest = Request::create($referer, 'GET');
            $refererRoute = app('router')->getRoutes()->match($refererRequest);
            $refererRouteName = $refererRoute->getName();
            if ($refererRouteName === null) {
                return null;
            }
            $refererRequest->setRouteResolver(static fn () => $refererRoute);

            return self::groupKeyForRoute($refererRouteName, $groups, $refererRequest);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The module groups the current user can actually open.
     *
     * When every item in a group is access-denied, {@see firstUrlFor()} has no
     * landing page to offer and falls back to the panel home — which bounces
     * the user straight back to where they started. Rendering such a group as a
     * topbar tab advertises a module the user cannot use and looks like a dead
     * button, so it is hidden instead.
     *
     * @return list<ModuleGroup>
     */
    public static function accessibleGroups(): array
    {
        return self::remember('accessible-groups', static fn (): array => array_values(array_filter(
            self::groups(),
            self::hasAccessibleLandingItem(...),
        )));
    }

    /**
     * Determine module visibility without constructing every Filament NavigationItem.
     *
     * @param  ModuleGroup  $group
     */
    private static function hasAccessibleLandingItem(array $group): bool
    {
        foreach ($group['items'] as $item) {
            if (self::resolveItemUrl($item) !== null || ! self::isItemAccessDenied($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  ModuleGroup  $group
     */
    public static function firstUrlFor(array $group): string
    {
        return self::remember('first-url|'.$group['key'], static fn (): string => self::computeFirstUrl($group));
    }

    /** @param ModuleGroup $group */
    private static function computeFirstUrl(array $group): string
    {
        $placeholderItem = null;
        foreach ($group['items'] as $item) {
            $link = self::resolveItemUrl($item);
            if ($link !== null) {
                return $link;
            }
            if (self::isItemAccessDenied($item)) {
                continue;
            }
            $placeholderItem ??= $item;
        }
        if ($placeholderItem === null) {
            return Filament::getUrl() ?? url('/admin');
        }

        return ModulePlaceholder::getUrl(['group' => $group['key'], 'item' => self::itemSlug($placeholderItem['label'])]);
    }

    /**
     * @param  ModuleGroup  $group
     * @return list<NavigationItem>
     */
    public static function registeredNavigationItemsFor(array $group, ?string $onlySection = null): array
    {
        return self::remember(
            'registered-items|'.$group['key'].'|'.($onlySection ?? ''),
            static fn (): array => self::computeRegisteredNavigationItems($group, $onlySection),
        );
    }

    /**
     * @param  ModuleGroup  $group
     * @return list<NavigationItem>
     */
    private static function computeRegisteredNavigationItems(array $group, ?string $onlySection): array
    {
        $items = [];
        foreach ($group['items'] as $item) {
            if ($onlySection !== null && ($item['section'] ?? null) !== $onlySection) {
                continue;
            }
            if (self::resolveItemUrl($item) === null) {
                continue;
            }
            if (isset($item['tabs'])) {
                $items[] = WorkspaceNavigation::navigationItem($item);

                continue;
            }
            if (isset($item['page']) && is_subclass_of($item['link'], Resource::class)) {
                $resource = $item['link'];
                $page = $item['page'];
                $items[] = NavigationItem::make($item['label'])
                    ->label(fn (): string => __($item['label']))
                    ->icon($item['icon'] ?? $resource::getNavigationIcon())
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
     * @param  list<ModuleGroup>|null  $groups
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
                if (self::isItemAccessDenied($item)) {
                    continue;
                }
                if (self::resolveItemUrl($item) !== null) {
                    continue;
                }
                $itemSlug = self::itemSlug($item['label']);
                $items[] = NavigationItem::make($item['label'])
                    ->label(fn (): string => __($item['label']))
                    ->group(fn (): string => __($group['label']))
                    ->sort(($group['sort'] * 100) + $index)
                    ->url(fn (): string => ModulePlaceholder::getUrl(['group' => $group['key'], 'item' => $itemSlug]))
                    ->isActiveWhen(fn (): bool => request()->routeIs(ModulePlaceholder::getRouteName())
                        && request()->query('group') === $group['key']
                        && request()->query('item') === $itemSlug);
            }
        }

        return $items;
    }

    /**
     * The landing URL of a group item: a workspace lands on its first tab the user may open, any
     * other item on its own link.
     *
     * @param  ModuleItem  $item
     */
    public static function resolveItemUrl(array $item): ?string
    {
        return isset($item['tabs']) ? WorkspaceNavigation::firstUrl($item) : self::resolveLink($item['link']);
    }

    /**
     * @param  ModuleItem  $item
     */
    public static function isItemAccessDenied(array $item): bool
    {
        return isset($item['tabs']) ? WorkspaceNavigation::firstUrl($item) === null : self::isAccessDenied($item['link']);
    }

    private static function itemSlug(string $labelKey): string
    {
        return Str::afterLast($labelKey, '.');
    }
}
