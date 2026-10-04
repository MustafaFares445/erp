<?php

declare(strict_types=1);

namespace App\Reporting;

use App\Enums\CrmReportType;
use App\Enums\EmployeeReportType;
use App\Enums\FinancialReportType;
use App\Enums\InventoryReportType;
use App\Enums\SalesReportType;
use App\Filament\Resources\CrmReports\CrmReportResource;
use App\Filament\Resources\EmployeeReports\EmployeeReportResource;
use App\Filament\Resources\FinancialReports\FinancialReportResource;
use App\Filament\Resources\InventoryReports\InventoryReportResource;
use App\Filament\Resources\PurchasingReports\PurchasingReportResource;
use App\Filament\Resources\SalesReports\SalesReportResource;
use App\Filament\Resources\SupportReports\SupportReportResource;
use App\Models\User;
use App\Services\Employees\EmployeeReportService;
use App\Services\Inventory\InventoryReportService;
use App\Services\Sales\SalesReportService;

/**
 * Discovery registry for the hybrid reporting information architecture.
 *
 * Reports stay implemented and authorized by their source domains. This
 * registry only exposes metadata used by the global Reports & Analytics hub.
 */
final class ReportRegistry
{
    /**
     * @return list<array{
     *     key:string,
     *     label:string,
     *     description:string,
     *     icon:string,
     *     reports:list<ReportDefinition>
     * }>
     */
    public static function accessibleDomains(?User $actor = null): array
    {
        $actor ??= auth()->user();

        if (! $actor instanceof User) {
            return [];
        }

        $reports = self::definitions($actor);
        $domains = [];

        foreach (self::domainMetadata() as $key => $metadata) {
            $domainReports = array_values(array_filter(
                $reports,
                static fn (ReportDefinition $report): bool => $report->domain === $key && $report->url() !== null,
            ));

            if ($domainReports === []) {
                continue;
            }

            $domains[] = [
                'key' => $key,
                'label' => __($metadata['label']),
                'description' => __($metadata['description']),
                'icon' => $metadata['icon'],
                'reports' => $domainReports,
            ];
        }

        return $domains;
    }

    /** @return list<ReportDefinition> */
    public static function definitions(User $actor): array
    {
        return [
            ...self::financialDefinitions(),
            ...self::salesDefinitions($actor),
            ...self::inventoryDefinitions($actor),
            ...self::purchasingDefinitions(),
            ...self::crmDefinitions(),
            ...self::employeeDefinitions($actor),
            ...self::supportDefinitions(),
        ];
    }

    /**
     * @return array<string, array{label:string,description:string,icon:string}>
     */
    private static function domainMetadata(): array
    {
        return [
            'financial' => [
                'label' => 'reporting.domains.financial.label',
                'description' => 'reporting.domains.financial.description',
                'icon' => 'heroicon-o-calculator',
            ],
            'sales' => [
                'label' => 'reporting.domains.sales.label',
                'description' => 'reporting.domains.sales.description',
                'icon' => 'heroicon-o-shopping-cart',
            ],
            'inventory' => [
                'label' => 'reporting.domains.inventory.label',
                'description' => 'reporting.domains.inventory.description',
                'icon' => 'heroicon-o-cube',
            ],
            'purchasing' => [
                'label' => 'reporting.domains.purchasing.label',
                'description' => 'reporting.domains.purchasing.description',
                'icon' => 'heroicon-o-shopping-bag',
            ],
            'crm' => [
                'label' => 'reporting.domains.crm.label',
                'description' => 'reporting.domains.crm.description',
                'icon' => 'heroicon-o-user-group',
            ],
            'employees' => [
                'label' => 'reporting.domains.employees.label',
                'description' => 'reporting.domains.employees.description',
                'icon' => 'heroicon-o-identification',
            ],
            'support' => [
                'label' => 'reporting.domains.support.label',
                'description' => 'reporting.domains.support.description',
                'icon' => 'heroicon-o-wrench-screwdriver',
            ],
        ];
    }

    /** @return list<ReportDefinition> */
    private static function financialDefinitions(): array
    {
        $categories = [
            FinancialReportType::TrialBalance->value => 'reporting.categories.financial_statements',
            FinancialReportType::ProfitAndLoss->value => 'reporting.categories.financial_statements',
            FinancialReportType::BalanceSheet->value => 'reporting.categories.financial_statements',
            FinancialReportType::GeneralLedger->value => 'reporting.categories.ledger',
            FinancialReportType::PostingRegister->value => 'reporting.categories.ledger',
        ];

        return array_map(
            static fn (FinancialReportType $type): ReportDefinition => new ReportDefinition(
                key: 'financial.'.$type->value,
                domain: 'financial',
                category: __($categories[$type->value]),
                label: $type->label(),
                description: __('reporting.reports.financial.'.$type->value),
                resource: FinancialReportResource::class,
                parameters: ['reportType' => $type->value],
            ),
            FinancialReportType::cases(),
        );
    }

    /** @return list<ReportDefinition> */
    private static function salesDefinitions(User $actor): array
    {
        $categories = [
            SalesReportType::QuotationFunnel->value => 'reporting.categories.conversion',
            SalesReportType::WinLossAnalysis->value => 'reporting.categories.conversion',
            SalesReportType::ConversionVelocity->value => 'reporting.categories.conversion',
            SalesReportType::DeliveredNotInvoiced->value => 'reporting.categories.exceptions',
            SalesReportType::InvoicedNotCollected->value => 'reporting.categories.exceptions',
            SalesReportType::ReturnsWithoutCredit->value => 'reporting.categories.exceptions',
            SalesReportType::CustomerRevenue->value => 'reporting.categories.revenue',
            SalesReportType::TaxRecognitionSummary->value => 'reporting.categories.compliance',
            SalesReportType::DiscountAndFloorOverrides->value => 'reporting.categories.compliance',
        ];

        $service = app(SalesReportService::class);
        $available = array_values(array_filter(
            SalesReportType::cases(),
            static fn (SalesReportType $type): bool => $service->canView($actor, $type),
        ));

        return array_map(
            static fn (SalesReportType $type): ReportDefinition => new ReportDefinition(
                key: 'sales.'.$type->value,
                domain: 'sales',
                category: __($categories[$type->value]),
                label: $type->label(),
                description: __('reporting.reports.sales.'.$type->value),
                resource: SalesReportResource::class,
                parameters: ['reportType' => $type->value],
            ),
            $available,
        );
    }

    /** @return list<ReportDefinition> */
    private static function inventoryDefinitions(User $actor): array
    {
        $available = app(InventoryReportService::class)->availableReports($actor);
        $categories = [
            InventoryReportType::Catalog->value => 'reporting.categories.catalog_suppliers',
            InventoryReportType::SupplierComparison->value => 'reporting.categories.catalog_suppliers',
            InventoryReportType::StockLevels->value => 'reporting.categories.stock_availability',
            InventoryReportType::Devices->value => 'reporting.categories.stock_availability',
            InventoryReportType::ExpiryLots->value => 'reporting.categories.stock_availability',
            InventoryReportType::QuarantineAgeing->value => 'reporting.categories.stock_availability',
            InventoryReportType::Movements->value => 'reporting.categories.movements_control',
            InventoryReportType::ConditionChanges->value => 'reporting.categories.movements_control',
            InventoryReportType::CountVariance->value => 'reporting.categories.movements_control',
            InventoryReportType::Reconciliation->value => 'reporting.categories.movements_control',
            InventoryReportType::PriceHistory->value => 'reporting.categories.pricing',
            InventoryReportType::PricingTiers->value => 'reporting.categories.pricing',
            InventoryReportType::CustomerAssignments->value => 'reporting.categories.pricing',
            InventoryReportType::FloorOverrides->value => 'reporting.categories.pricing',
            InventoryReportType::ImportRuns->value => 'reporting.categories.imports',
            InventoryReportType::ImportResults->value => 'reporting.categories.imports',
        ];

        return array_map(
            static fn (InventoryReportType $type): ReportDefinition => new ReportDefinition(
                key: 'inventory.'.$type->value,
                domain: 'inventory',
                category: __($categories[$type->value]),
                label: $type->label(),
                description: __('reporting.reports.inventory.'.$type->value),
                resource: InventoryReportResource::class,
                parameters: ['report' => $type->value],
            ),
            $available,
        );
    }

    /** @return list<ReportDefinition> */
    private static function purchasingDefinitions(): array
    {
        $reports = [
            'open_commitments',
            'receiving_performance',
            'cost_variance',
            'duplicate_reference_attempts',
        ];

        return array_map(
            static fn (string $type): ReportDefinition => new ReportDefinition(
                key: 'purchasing.'.$type,
                domain: 'purchasing',
                category: __('reporting.categories.purchasing_performance'),
                label: __('reporting.labels.purchasing.'.$type),
                description: __('reporting.reports.purchasing.'.$type),
                resource: PurchasingReportResource::class,
                parameters: ['reportType' => $type],
            ),
            $reports,
        );
    }

    /** @return list<ReportDefinition> */
    private static function crmDefinitions(): array
    {
        return array_map(
            static fn (CrmReportType $type): ReportDefinition => new ReportDefinition(
                key: 'crm.'.$type->value,
                domain: 'crm',
                category: in_array($type, [CrmReportType::CampaignPerformance, CrmReportType::AttributedRevenue], true)
                    ? __('reporting.categories.campaigns')
                    : __('reporting.categories.pipeline'),
                label: $type->label(),
                description: __('reporting.reports.crm.'.$type->value),
                resource: CrmReportResource::class,
                parameters: ['reportType' => $type->value],
            ),
            CrmReportType::cases(),
        );
    }

    /** @return list<ReportDefinition> */
    private static function employeeDefinitions(User $actor): array
    {
        $available = app(EmployeeReportService::class)->availableReports($actor);
        $categories = [
            EmployeeReportType::PlanCompletion->value => 'reporting.categories.tasks_visits',
            EmployeeReportType::OverdueTasks->value => 'reporting.categories.tasks_visits',
            EmployeeReportType::UnexecutedVisits->value => 'reporting.categories.tasks_visits',
            EmployeeReportType::PerformanceByEmployee->value => 'reporting.categories.performance',
            EmployeeReportType::PerformanceByMonth->value => 'reporting.categories.performance',
            EmployeeReportType::SalaryByEmployee->value => 'reporting.categories.salary',
            EmployeeReportType::SalaryByMonth->value => 'reporting.categories.salary',
        ];

        return array_map(
            static fn (EmployeeReportType $type): ReportDefinition => new ReportDefinition(
                key: 'employees.'.$type->value,
                domain: 'employees',
                category: __($categories[$type->value]),
                label: $type->label(),
                description: __('reporting.reports.employees.'.$type->value),
                resource: EmployeeReportResource::class,
                parameters: ['report' => $type->value],
            ),
            $available,
        );
    }

    /** @return list<ReportDefinition> */
    private static function supportDefinitions(): array
    {
        $reports = [
            'overview' => 'reporting.categories.service_desk',
            'service_desk' => 'reporting.categories.service_desk',
            'workload' => 'reporting.categories.service_desk',
            'field_service' => 'reporting.categories.field_service',
            'reliability_warranty' => 'reporting.categories.reliability_warranty',
            'financial' => 'reporting.categories.service_financial',
            'preventive' => 'reporting.categories.preventive',
        ];

        $definitions = [];

        foreach ($reports as $section => $category) {
            $definitions[] = new ReportDefinition(
                key: 'support.'.$section,
                domain: 'support',
                category: __($category),
                label: __('reporting.labels.support.'.$section),
                description: __('reporting.reports.support.'.$section),
                resource: SupportReportResource::class,
                parameters: ['section' => $section],
            );
        }

        return $definitions;
    }
}
