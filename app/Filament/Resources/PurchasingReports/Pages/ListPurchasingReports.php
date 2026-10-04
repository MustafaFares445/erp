<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchasingReports\Pages;

use App\Enums\PurchasePermission;
use App\Filament\Resources\PurchasingReports\PurchasingReportResource;
use App\Models\User;
use App\Services\Purchasing\PurchasingReportService;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ListPurchasingReports extends Page
{
    protected static string $resource = PurchasingReportResource::class;

    protected string $view = 'filament.purchasing-reports.list-purchasing-reports';

    #[Url]
    public string $reportType = 'open_commitments';

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.purchasing_reports');
    }

    /** @return array<string, array{label:string,description:string}> */
    public function reportOptions(): array
    {
        return [
            'open_commitments' => [
                'label' => __('reporting.labels.purchasing.open_commitments'),
                'description' => __('reporting.reports.purchasing.open_commitments'),
            ],
            'receiving_performance' => [
                'label' => __('reporting.labels.purchasing.receiving_performance'),
                'description' => __('reporting.reports.purchasing.receiving_performance'),
            ],
            'cost_variance' => [
                'label' => __('reporting.labels.purchasing.cost_variance'),
                'description' => __('reporting.reports.purchasing.cost_variance'),
            ],
            'duplicate_reference_attempts' => [
                'label' => __('reporting.labels.purchasing.duplicate_reference_attempts'),
                'description' => __('reporting.reports.purchasing.duplicate_reference_attempts'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function getViewData(): array
    {
        $this->authorizeReportAccess();

        $key = $this->currentReportType();
        $options = $this->reportOptions();
        $rows = $this->rowsFor($key);

        return [
            'reportKey' => $key,
            'reportLabel' => $options[$key]['label'],
            'reportDescription' => $options[$key]['description'],
            'rows' => $rows,
            'summary' => $this->summaryFor($key, $rows),
        ];
    }

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
        $this->authorizeReportAccess();

        $type = $this->currentReportType();
        $rows = $this->rowsFor($type);
        [$headings, $values] = $this->exportShape($type);

        return response()->streamDownload(function () use ($headings, $rows, $values): void {
            /** @var resource $handle */
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, $headings, escape: '\\');

            foreach ($rows as $row) {
                fputcsv($handle, $values($row), escape: '\\');
            }

            fclose($handle);
        }, 'purchasing-'.$type.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function currentReportType(): string
    {
        return array_key_exists($this->reportType, $this->reportOptions())
            ? $this->reportType
            : 'open_commitments';
    }

    /** @return list<array<string, mixed>> */
    private function rowsFor(string $type): array
    {
        $service = app(PurchasingReportService::class);

        return match ($type) {
            'receiving_performance' => $service->receivingPerformance(),
            'cost_variance' => $service->costVariance(),
            'duplicate_reference_attempts' => $service->duplicateReferenceAttempts(),
            default => $service->openCommitments(),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{label:string,value:int|string}>
     */
    private function summaryFor(string $type, array $rows): array
    {
        return match ($type) {
            'open_commitments' => [
                ['label' => __('Suppliers'), 'value' => count($rows)],
                ['label' => __('Open purchase orders'), 'value' => array_sum(array_map(static fn (array $row): int => self::intValue($row['orders'] ?? null), $rows))],
            ],
            'receiving_performance' => [
                ['label' => __('Suppliers'), 'value' => count($rows)],
                ['label' => __('Confirmed promises'), 'value' => array_sum(array_map(static fn (array $row): int => self::intValue($row['promised'] ?? null), $rows))],
                ['label' => __('On-time receipts'), 'value' => array_sum(array_map(static fn (array $row): int => self::intValue($row['on_time'] ?? null), $rows))],
            ],
            'cost_variance' => [
                ['label' => __('Variance lines'), 'value' => count($rows)],
                ['label' => __('Suppliers'), 'value' => count(array_unique(array_filter(array_map(static fn (array $row): string => self::stringValue($row['supplier'] ?? null), $rows), static fn (string $supplier): bool => $supplier !== '')))],
            ],
            default => [
                ['label' => __('Rejected attempts'), 'value' => count($rows)],
                ['label' => __('Suppliers'), 'value' => count(array_unique(array_filter(array_map(static fn (array $row): string => self::stringValue($row['supplier'] ?? null), $rows), static fn (string $supplier): bool => $supplier !== '')))],
            ],
        };
    }

    /**
     * @return array{0:list<string>,1:callable(array<string,mixed>):list<string|int|float|null>}
     */
    private function exportShape(string $type): array
    {
        return match ($type) {
            'receiving_performance' => [
                ['supplier', 'promised', 'on_time', 'on_time_rate_percent'],
                static fn (array $row): array => [
                    self::stringValue($row['supplier'] ?? null),
                    self::intValue($row['promised'] ?? null),
                    self::intValue($row['on_time'] ?? null),
                    self::floatValue($row['on_time_rate'] ?? null),
                ],
            ],
            'cost_variance' => [
                ['purchase_order', 'supplier', 'currency', 'variant', 'ordered_cost', 'received_cost', 'variance'],
                static fn (array $row): array => [
                    self::stringValue($row['purchase_order_number'] ?? null),
                    self::stringValue($row['supplier'] ?? null),
                    self::stringValue($row['currency_code'] ?? null),
                    self::stringValue($row['variant'] ?? null),
                    self::floatValue($row['ordered_cost'] ?? null),
                    self::floatValue($row['received_cost'] ?? null),
                    self::floatValue($row['variance'] ?? null),
                ],
            ],
            'duplicate_reference_attempts' => [
                ['attempted_at', 'supplier', 'supplier_reference', 'attempted_by', 'reason'],
                static fn (array $row): array => [
                    self::stringValue($row['attempted_at'] ?? null),
                    self::stringValue($row['supplier'] ?? null),
                    self::stringValue($row['supplier_reference'] ?? null),
                    self::stringValue($row['attempted_by'] ?? null),
                    self::stringValue($row['message'] ?? null),
                ],
            ],
            default => [
                ['supplier', 'currency', 'orders', 'ordered_value', 'received_value', 'outstanding_value'],
                static fn (array $row): array => [
                    self::stringValue($row['supplier'] ?? null),
                    self::stringValue($row['currency_code'] ?? null),
                    self::intValue($row['orders'] ?? null),
                    self::floatValue($row['ordered_value'] ?? null),
                    self::floatValue($row['received_value'] ?? null),
                    self::floatValue($row['outstanding_value'] ?? null),
                ],
            ],
        };
    }

    private static function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function floatValue(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function authorizeReportAccess(): void
    {
        abort_unless($this->canViewReports(), 403);
    }

    private function canViewReports(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->can(PurchasePermission::ReportView->value);
    }
}
