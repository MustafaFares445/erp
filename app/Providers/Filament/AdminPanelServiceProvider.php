<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\AdminModuleRegistry;
use App\Filament\Pages\AccountingDashboard;
use App\Filament\Pages\CatalogSetup;
use App\Filament\Pages\CrmDashboard;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\EmployeesDashboard;
use App\Filament\Pages\InventoryDashboard;
use App\Filament\Pages\ModulePlaceholder;
use App\Filament\Pages\PurchasingDashboard;
use App\Filament\Pages\SalesDashboard;
use App\Filament\Pages\SupportDashboard;
use App\Filament\Resources\AccountsPayable\AccountsPayableResource;
use App\Filament\Resources\AccountsReceivable\AccountsReceivableResource;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\ChartOfAccounts\ChartOfAccountResource;
use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\DashboardUsers\DashboardUserResource;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\EmployeeReports\EmployeeReportResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\FinancialReports\FinancialReportResource;
use App\Filament\Resources\FiscalPeriods\FiscalPeriodResource;
use App\Filament\Resources\Interactions\InteractionResource;
use App\Filament\Resources\InventoryAlerts\InventoryAlertResource;
use App\Filament\Resources\InventoryCorrections\InventoryCorrectionResource;
use App\Filament\Resources\InventoryImportRuns\InventoryImportRunResource;
use App\Filament\Resources\InventoryLots\InventoryLotResource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\InventoryReports\InventoryReportResource;
use App\Filament\Resources\InventoryReservations\InventoryReservationResource;
use App\Filament\Resources\InventorySettings\InventorySettingResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Resources\MonthlyPlans\MonthlyPlanResource;
use App\Filament\Resources\NotificationDeliveries\NotificationDeliveryResource;
use App\Filament\Resources\NotificationPreferences\NotificationPreferenceResource;
use App\Filament\Resources\NotificationTemplates\NotificationTemplateResource;
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
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseSettings\PurchaseSettingResource;
use App\Filament\Resources\PurchasingReports\PurchasingReportResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\Refunds\RefundResource;
use App\Filament\Resources\Returns\ReturnResource;
use App\Filament\Resources\SalaryCalculations\SalaryCalculationResource;
use App\Filament\Resources\SalesOpportunities\SalesOpportunityResource;
use App\Filament\Resources\SalesSettings\SalesSettingResource;
use App\Filament\Resources\SerializedInventoryUnits\SerializedInventoryUnitResource;
use App\Filament\Resources\ServiceRecords\ServiceRecordResource;
use App\Filament\Resources\ShipmentAttachments\ShipmentAttachmentResource;
use App\Filament\Resources\SlaPolicies\SlaPolicyResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\SupplierConfirmations\SupplierConfirmationResource;
use App\Filament\Resources\SupplierPayments\SupplierPaymentResource;
use App\Filament\Resources\SupplierProductReferences\SupplierProductReferenceResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Resources\SupportReports\SupportReportResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Taxes\TaxResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Filament\Resources\Warehouses\WarehouseResource;
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
            ->databaseNotifications()
            ->colors(['primary' => Color::Amber])
            ->maxContentWidth(Width::Full)
            ->resources([
                AccountsPayableResource::class,
                AccountsReceivableResource::class,
                AdjustmentResource::class,
                AuditLogResource::class,
                BillResource::class,
                CampaignResource::class,
                ChartOfAccountResource::class,
                CustomerResource::class,
                CreditNoteResource::class,
                DeliveryNoteResource::class,
                DashboardUserResource::class,
                EmployeeReportResource::class,
                EmployeeResource::class,
                ExpenseResource::class,
                FinancialReportResource::class,
                FiscalPeriodResource::class,
                InteractionResource::class,
                InventoryAlertResource::class,
                InventoryCorrectionResource::class,
                InventoryImportRunResource::class,
                InventoryLotResource::class,
                InventoryOperationResource::class,
                InventoryReportResource::class,
                InventorySettingResource::class,
                InvoiceResource::class,
                JournalEntryResource::class,
                LeadResource::class,
                MaintenanceRequestResource::class,
                MonthlyPlanResource::class,
                NotificationDeliveryResource::class,
                NotificationPreferenceResource::class,
                NotificationTemplateResource::class,
                OrderResource::class,
                PackageTypeResource::class,
                PackageResource::class,
                PaymentTermResource::class,
                PaymentMethodResource::class,
                PaymentResource::class,
                PerformanceResource::class,
                PriceFloorOverrideResource::class,
                PriceHistoryResource::class,
                PricingTierResource::class,
                ProductVariantResource::class,
                ProductResource::class,
                PurchaseOrderResource::class,
                PurchaseSettingResource::class,
                PurchasingReportResource::class,
                QuotationResource::class,
                RefundResource::class,
                ReturnResource::class,
                SalaryCalculationResource::class,
                SalesOpportunityResource::class,
                SalesSettingResource::class,
                SupplierPaymentResource::class,
                SerializedInventoryUnitResource::class,
                ServiceRecordResource::class,
                ShipmentAttachmentResource::class,
                SlaPolicyResource::class,
                StockLevelResource::class,
                StockMovementResource::class,
                InventoryReservationResource::class,
                SupplierConfirmationResource::class,
                SupplierProductReferenceResource::class,
                SupplierResource::class,
                SupportReportResource::class,
                TaskResource::class,
                TaxResource::class,
                TicketResource::class,
                VisitResource::class,
                WarehouseResource::class,
            ])
            ->pages([
                AccountingDashboard::class,
                CatalogSetup::class,
                CrmDashboard::class,
                Dashboard::class,
                EmployeesDashboard::class,
                InventoryDashboard::class,
                ModulePlaceholder::class,
                PurchasingDashboard::class,
                SalesDashboard::class,
                SupportDashboard::class,
            ])
            ->assets([
                AlpineComponent::make('customer-delivery-map', resource_path('js/filament/customer-delivery-map.js')),
                AlpineComponent::make('customer-location-picker', resource_path('js/filament/customer-location-picker.js')),
                AlpineComponent::make('visit-gps-trail-map', resource_path('js/filament/visit-gps-trail-map.js')),
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
            ->authMiddleware([Authenticate::class]);
    }

    private function navigation(NavigationBuilder $builder): NavigationBuilder
    {
        $items = Dashboard::getNavigationItems();
        $activeKey = AdminModuleRegistry::activeGroupKey();

        if ($activeKey === null) {
            return $builder->items($items);
        }

        $activeGroup = collect(AdminModuleRegistry::groups())->firstWhere('key', $activeKey);

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

                    $builder->group(NavigationGroup::make(fn (): string => __($section['label']))->items($sectionItems));
                }

                return $builder->items($items);
            }

            $items = [...$items, ...AdminModuleRegistry::registeredNavigationItemsFor($activeGroup)];
        }

        return $builder->items([...$items, ...AdminModuleRegistry::navigationItems(onlyGroupKey: $activeKey)]);
    }
}
