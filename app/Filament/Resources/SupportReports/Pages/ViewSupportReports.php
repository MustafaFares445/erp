<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportReports\Pages;

use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Resources\SerializedInventoryUnits\SerializedInventoryUnitResource;
use App\Filament\Resources\SupportReports\SupportReportResource;
use App\Models\User;
use App\Services\Settings\CurrencyCatalogService;
use App\Services\Support\SupportLifecycleReportService;
use App\Services\Support\SupportReportService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class ViewSupportReports extends Page
{
    protected static string $resource = SupportReportResource::class;

    protected string $view = 'filament.support-reports.view-support-reports';

    #[Url]
    public string $section = 'overview';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $until = null;

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.support_reports');
    }

    /** @return array<string,array{label:string,description:string}> */
    public function sectionOptions(): array
    {
        $options = [];

        foreach ([
            'overview',
            'service_desk',
            'workload',
            'field_service',
            'reliability_warranty',
            'equipment_lifecycle',
            'product_quality',
            'financial',
            'preventive',
        ] as $key) {
            $options[$key] = [
                'label' => __('reporting.labels.support.'.$key),
                'description' => __('reporting.reports.support.'.$key),
            ];
        }

        return $options;
    }

    public function sectionDescription(): string
    {
        return $this->sectionOptions()[$this->sectionKey()]['description'];
    }

    public function usesPeriod(): bool
    {
        return $this->sectionKey() !== 'workload';
    }

    public function clearPeriod(): void
    {
        $this->from = null;
        $this->until = null;
    }

    /** @return array<string,mixed> */
    #[\Override]
    public function getViewData(): array
    {
        $service = app(SupportReportService::class);
        $lifecycle = app(SupportLifecycleReportService::class);
        $actor = $this->actor();
        $from = $this->parseDate($this->from);
        $until = $this->parseDate($this->until);
        $section = $this->sectionKey();

        $base = [
            'sectionKey' => $section,
            'sectionLabel' => $this->sectionOptions()[$section]['label'],
            'currency' => app(CurrencyCatalogService::class)->defaultCode(),
        ];

        return [
            ...$base,
            ...match ($section) {
                'service_desk' => [
                    'sla' => $service->sla($actor, $from, $until),
                    'slaCompliance' => $service->slaCompliance($actor, $from, $until),
                    'responseTime' => $service->responseTime($actor, $from, $until),
                    'resolutionTime' => $service->resolutionTime($actor, $from, $until),
                    'reopenRate' => $service->reopenRate($actor, $from, $until),
                    'customerSatisfaction' => $service->customerSatisfaction($actor, $from, $until),
                ],
                'workload' => [
                    'workload' => $service->workload($actor),
                    'backlogAging' => $service->backlogAging($actor),
                    'assignmentLoad' => $service->assignmentLoad($actor),
                ],
                'field_service' => [
                    'technicianUtilization' => $service->technicianUtilization($actor, $from, $until),
                    'maintenance' => $service->maintenance($actor, $from, $until),
                ],
                'reliability_warranty' => [
                    'repeatFailures' => $service->repeatFailures($actor, $from, $until),
                    'warrantyRecoveryPerformance' => $service->warrantyRecoveryPerformance($actor, $from, $until),
                ],
                'equipment_lifecycle' => [
                    'installationReport' => $lifecycle->installations($actor, $from, $until),
                    'calibrationReport' => $lifecycle->calibrations($actor, $from, $until),
                    'loanerReport' => $lifecycle->loaners($actor, $from, $until),
                    'rmaReport' => $lifecycle->rma($actor, $from, $until),
                ],
                'product_quality' => [
                    'qualityReport' => $lifecycle->quality($actor, $from, $until),
                ],
                'financial' => [
                    'serviceMargin' => $service->serviceMargin($actor, $from, $until),
                ],
                'preventive' => [
                    'preventiveCompliance' => $service->preventiveCompliance($actor, $from, $until),
                ],
                default => [
                    'workload' => $service->workload($actor),
                    'slaCompliance' => $service->slaCompliance($actor, $from, $until),
                    'responseTime' => $service->responseTime($actor, $from, $until),
                    'resolutionTime' => $service->resolutionTime($actor, $from, $until),
                    'customerSatisfaction' => $service->customerSatisfaction($actor, $from, $until),
                ],
            },
        ];
    }

    /** @return array<int, Action> */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_current_report')
                ->label(__('reporting.actions.export_current'))
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $this->canViewReports())
                ->authorize(fn (): bool => $this->canViewReports())
                ->action(fn (): StreamedResponse => $this->exportCurrentReport()),
        ];
    }

    public function exportCurrentReport(): StreamedResponse
    {
        $actor = $this->actor();
        abort_unless($this->canViewReports(), 403);

        $service = app(SupportReportService::class);
        $section = $this->sectionKey();
        $from = $this->parseDate($this->from);
        $until = $this->parseDate($this->until);
        $currency = app(CurrencyCatalogService::class)->defaultCode();
        $rows = $this->exportRows($section, $service, $actor, $from, $until, $currency);

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                throw new \RuntimeException('Unable to open the support report export stream.');
            }

            foreach ($rows as $row) {
                $csvRow = array_map(
                    static fn (mixed $value): string|int|float|null => self::csvValue($value),
                    $row,
                );

                fputcsv($handle, $csvRow, escape: '\\');
            }

            fclose($handle);
        }, 'support-'.$section.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return list<list<mixed>>
     */
    private function exportRows(
        string $section,
        SupportReportService $service,
        User $actor,
        ?Carbon $from,
        ?Carbon $until,
        string $currency,
    ): array {
        return match ($section) {
            'service_desk' => $this->serviceDeskExportRows($service, $actor, $from, $until),
            'workload' => $this->workloadExportRows($service, $actor),
            'field_service' => $this->fieldServiceExportRows($service, $actor, $from, $until),
            'reliability_warranty' => $this->reliabilityExportRows($service, $actor, $from, $until, $currency),
            'equipment_lifecycle' => $this->equipmentLifecycleExportRows(app(SupportLifecycleReportService::class), $actor, $from, $until, $currency),
            'product_quality' => $this->productQualityExportRows(app(SupportLifecycleReportService::class), $actor, $from, $until),
            'financial' => $this->financialExportRows($service, $actor, $from, $until, $currency),
            'preventive' => $this->preventiveExportRows($service, $actor, $from, $until),
            default => $this->overviewExportRows($service, $actor, $from, $until),
        };
    }

    /** @return list<list<mixed>> */
    private function overviewExportRows(SupportReportService $service, User $actor, ?Carbon $from, ?Carbon $until): array
    {
        $workload = $service->workload($actor);
        $sla = $service->slaCompliance($actor, $from, $until);
        $response = $service->responseTime($actor, $from, $until);
        $resolution = $service->resolutionTime($actor, $from, $until);
        $csat = $service->customerSatisfaction($actor, $from, $until);

        return [
            [__('Metric'), __('Value')],
            [__('Open tickets'), $workload['total_open']],
            [__('SLA compliance'), $sla['compliance_percent']],
            [__('Average first response (minutes)'), $response['average_minutes']],
            [__('Average resolution (minutes)'), $resolution['average_minutes']],
            [__('CSAT'), $csat['average_rating']],
        ];
    }

    /** @return list<list<mixed>> */
    private function serviceDeskExportRows(SupportReportService $service, User $actor, ?Carbon $from, ?Carbon $until): array
    {
        $sla = $service->sla($actor, $from, $until);
        $compliance = $service->slaCompliance($actor, $from, $until);
        $response = $service->responseTime($actor, $from, $until);
        $resolution = $service->resolutionTime($actor, $from, $until);
        $reopen = $service->reopenRate($actor, $from, $until);
        $csat = $service->customerSatisfaction($actor, $from, $until);

        return [
            [__('Metric'), __('Value')],
            [__('Average first response (minutes)'), $response['average_minutes']],
            [__('Average resolution (minutes)'), $resolution['average_minutes']],
            [__('SLA compliance'), $compliance['compliance_percent']],
            [__('Response breaches'), $sla['response_breaches']],
            [__('Resolution breaches'), $sla['resolution_breaches']],
            [__('Reopen rate'), $reopen['reopen_rate_percent']],
            [__('CSAT'), $csat['average_rating']],
            [__('CSAT responses'), $csat['responses']],
        ];
    }

    /** @return list<list<mixed>> */
    private function workloadExportRows(SupportReportService $service, User $actor): array
    {
        $workload = $service->workload($actor);
        $ageing = $service->backlogAging($actor);
        $assignment = $service->assignmentLoad($actor);

        $rows = [
            [__('Dimension'), __('Label'), __('Value')],
            [__('Summary'), __('Open tickets'), $workload['total_open']],
            [__('Backlog age'), __('Under 24 hours'), $ageing['under_24h']],
            [__('Backlog age'), __('1–3 days'), $ageing['one_to_three_days']],
            [__('Backlog age'), __('4–7 days'), $ageing['four_to_seven_days']],
            [__('Backlog age'), __('Over 7 days'), $ageing['over_seven_days']],
        ];

        foreach ($workload['by_status'] as $status => $count) {
            $rows[] = [__('Status'), (string) $status, $count];
        }

        foreach ($workload['by_priority'] as $priority => $count) {
            $rows[] = [__('Priority'), (string) $priority, $count];
        }

        foreach ($assignment as $row) {
            $rows[] = [__('Assignee'), $row['name'], $row['count']];
        }

        return $rows;
    }

    /** @return list<list<mixed>> */
    private function fieldServiceExportRows(SupportReportService $service, User $actor, ?Carbon $from, ?Carbon $until): array
    {
        $maintenance = $service->maintenance($actor, $from, $until);
        $technicians = $service->technicianUtilization($actor, $from, $until);

        $rows = [
            [__('Metric'), __('Value')],
            [__('Open maintenance requests'), $maintenance['open_requests']],
            [__('Overdue service records'), $maintenance['overdue_service_records']],
            [__('Parts consumed'), $maintenance['parts_consumed']],
            [],
            [__('Technician'), __('Appointments'), __('Scheduled minutes'), __('Actual on-site minutes')],
        ];

        foreach ($technicians as $row) {
            $rows[] = [
                $row['name'],
                $row['appointment_count'],
                $row['scheduled_minutes'],
                $row['actual_on_site_minutes'],
            ];
        }

        return $rows;
    }

    /** @return list<list<mixed>> */
    private function reliabilityExportRows(
        SupportReportService $service,
        User $actor,
        ?Carbon $from,
        ?Carbon $until,
        string $currency,
    ): array {
        $recovery = $service->warrantyRecoveryPerformance($actor, $from, $until);
        $failures = $service->repeatFailures($actor, $from, $until);

        $rows = [
            [__('Metric'), __('Currency'), __('Value')],
            [__('Recovery claims'), null, $recovery['claims']],
            [__('Claimed'), $currency, $recovery['claimed_minor'] / 100],
            [__('Received'), $currency, $recovery['received_minor'] / 100],
            [__('Outstanding'), $currency, $recovery['outstanding_minor'] / 100],
            [__('Recovery rate'), null, $recovery['recovery_percent']],
            [],
            [__('Equipment unit'), __('Failure category'), __('Occurrences')],
        ];

        foreach ($failures as $row) {
            $rows[] = [
                $row['serialized_inventory_unit_id'],
                $row['failure_category'],
                $row['count'],
            ];
        }

        return $rows;
    }

    /** @return list<list<mixed>> */
    private function financialExportRows(
        SupportReportService $service,
        User $actor,
        ?Carbon $from,
        ?Carbon $until,
        string $currency,
    ): array {
        $margin = $service->serviceMargin($actor, $from, $until);

        $rows = [
            [__('Metric'), __('Currency'), __('Value')],
            [__('Total cost'), $currency, $margin['total_cost_minor'] / 100],
            [__('Total revenue'), $currency, $margin['total_revenue_minor'] / 100],
            [__('Total margin'), $currency, $margin['total_margin_minor'] / 100],
            [__('Warranty cost'), $currency, $margin['warranty_cost_minor'] / 100],
            [],
            [__('Job'), __('Customer'), __('Equipment'), __('Billing'), __('Currency'), __('Cost'), __('Revenue'), __('Margin')],
        ];

        foreach ($margin['jobs'] as $job) {
            $rows[] = [
                $job['maintenance_record_id'],
                $job['customer'],
                $job['equipment'],
                $job['billing_type'],
                $currency,
                $job['cost_minor'] / 100,
                $job['revenue_minor'] / 100,
                $job['margin_minor'] / 100,
            ];
        }

        return $rows;
    }

    /** @return list<list<mixed>> */
    private function preventiveExportRows(SupportReportService $service, User $actor, ?Carbon $from, ?Carbon $until): array
    {
        $compliance = $service->preventiveCompliance($actor, $from, $until);

        $rows = [
            [__('Metric'), __('Value')],
            [__('Total due'), $compliance['total_due']],
            [__('Raised'), $compliance['raised']],
            [__('Completed'), $compliance['completed']],
            [__('Missed'), $compliance['missed']],
            [__('Skipped'), $compliance['skipped']],
            [],
            [__('Customer'), __('Due'), __('Completed'), __('Missed')],
        ];

        foreach ($compliance['by_customer'] as $row) {
            $rows[] = [$row['customer'], $row['due'], $row['completed'], $row['missed']];
        }

        $rows[] = [];
        $rows[] = [__('Equipment'), __('Due'), __('Completed'), __('Missed')];

        foreach ($compliance['by_equipment'] as $row) {
            $rows[] = [$row['equipment'], $row['due'], $row['completed'], $row['missed']];
        }

        return $rows;
    }

    /** @return list<list<mixed>> */
    private function equipmentLifecycleExportRows(
        SupportLifecycleReportService $service,
        User $actor,
        ?Carbon $from,
        ?Carbon $until,
        string $currency,
    ): array {
        $installation = $service->installations($actor, $from, $until);
        $calibration = $service->calibrations($actor, $from, $until);
        $loaners = $service->loaners($actor, $from, $until);
        $rma = $service->rma($actor, $from, $until);

        return [
            [__('Area'), __('Metric'), __('Value')],
            [__('Installation'), __('Completed installations'), $installation['completed']],
            [__('Installation'), __('Pending commissioning'), $installation['pending_commissioning']],
            [__('Installation'), __('Commissioning failures'), $installation['commissioning_failed']],
            [__('Installation'), __('Commissioning failure rate'), $installation['commissioning_failure_rate_percent']],
            [__('Installation'), __('Average delivery-to-installation hours'), $installation['average_delivery_to_installation_hours']],
            [__('Calibration'), __('Calibrations due soon'), $calibration['due_soon']],
            [__('Calibration'), __('Overdue calibrations'), $calibration['overdue']],
            [__('Calibration'), __('Calibration pass rate'), $calibration['pass_rate_percent']],
            [__('Calibration'), __('Calibration failure rate'), $calibration['failure_rate_percent']],
            [__('Loaners'), __('Active loaners'), $loaners['active']],
            [__('Loaners'), __('Overdue loaners'), $loaners['overdue']],
            [__('Loaners'), __('Loaner issues in period'), $loaners['utilization_count']],
            [__('Loaners'), __('Average loan duration days'), $loaners['average_loan_duration_days']],
            [__('RMA'), __('Open RMA cases'), $rma['open']],
            [__('RMA'), __('Awaiting supplier'), $rma['awaiting_supplier']],
            [__('RMA'), __('Average supplier turnaround days'), $rma['average_supplier_turnaround_days']],
            [__('RMA'), __('Warranty recovery outstanding'), $currency.' '.number_format($rma['warranty_recovery_outstanding_minor'] / 100, 2, '.', '')],
        ];
    }

    /** @return list<list<mixed>> */
    private function productQualityExportRows(
        SupportLifecycleReportService $service,
        User $actor,
        ?Carbon $from,
        ?Carbon $until,
    ): array {
        $quality = $service->quality($actor, $from, $until);
        $rows = [
            [__('Metric'), __('Value')],
            [__('Quality complaints'), $quality['complaints']],
            [__('Affected quantity'), $quality['affected_quantity']],
            [__('Returned quantity'), $quality['returned_quantity']],
            [],
            [__('Product'), __('Complaints'), __('Affected quantity')],
        ];

        foreach ($quality['by_product'] as $row) {
            $rows[] = [$row['product'], $row['complaints'], $row['affected_quantity']];
        }

        $rows[] = [];
        $rows[] = [__('Lot'), __('Complaints'), __('Affected customers'), __('Affected quantity')];

        foreach ($quality['by_lot'] as $row) {
            $rows[] = [$row['lot_number'], $row['complaints'], $row['affected_customers'], $row['affected_quantity']];
        }

        return $rows;
    }

    private static function csvValue(mixed $value): string|int|float|null
    {
        if ($value === null || is_string($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return '';
    }

    private function canViewReports(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(SupportReportService::class)->canView($actor);
    }

    public function maintenanceUrl(int $recordId): ?string
    {
        return $this->resourceRecordUrl(MaintenanceRequestResource::class, $recordId);
    }

    public function equipmentUrl(int $recordId): ?string
    {
        return $this->resourceRecordUrl(SerializedInventoryUnitResource::class, $recordId);
    }

    private function sectionKey(): string
    {
        return array_key_exists($this->section, $this->sectionOptions()) ? $this->section : 'overview';
    }

    private function actor(): User
    {
        $actor = auth()->user();

        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function parseDate(?string $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    private function resourceRecordUrl(string $resource, int $recordId): ?string
    {
        try {
            if (! $resource::canAccess()) {
                return null;
            }

            return $resource::getUrl('view', ['record' => $recordId]);
        } catch (Throwable) {
            return null;
        }
    }
}
