<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchasingReports\Pages;

use App\Enums\PurchasePermission;
use App\Filament\Resources\PurchasingReports\PurchasingReportResource;
use App\Models\User;
use App\Services\Purchasing\PurchasingReportService;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Open commitments, receiving performance, and cost variance.
 *
 * The CSV export is gated on the same `purchase.report.view` permission as the
 * page itself (SC-007): an export that checked a weaker rule than the screen it
 * exports would be a way to read the report without being allowed to see it.
 */
final class ListPurchasingReports extends Page
{
    protected static string $resource = PurchasingReportResource::class;

    protected string $view = 'filament.purchasing-reports.list-purchasing-reports';

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.purchasing_reports');
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function getViewData(): array
    {
        $service = app(PurchasingReportService::class);
        $this->authorizeReportAccess();

        return [
            'openCommitments' => $service->openCommitments(),
            'receivingPerformance' => $service->receivingPerformance(),
            'costVariance' => $service->costVariance(),
            'duplicateReferenceAttempts' => $service->duplicateReferenceAttempts(),
        ];
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportOpenCommitments')
                ->label(__('admin.purchasing.reports.open_commitments'))
                ->visible(fn (): bool => $this->canViewReports())
                ->authorize(fn (): bool => $this->canViewReports())
                ->action(fn (): StreamedResponse => $this->streamOpenCommitments()),
        ];
    }

    private function streamOpenCommitments(): StreamedResponse
    {
        $this->authorizeReportAccess();

        $rows = app(PurchasingReportService::class)->openCommitments();

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, ['supplier', 'orders', 'ordered_value', 'received_value', 'outstanding_value'], escape: '\\');

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['supplier'],
                    $row['orders'],
                    $row['ordered_value'],
                    $row['received_value'],
                    $row['outstanding_value'],
                ],
                    escape: '\\');
            }

            fclose($handle);
        }, 'purchasing-open-commitments.csv', ['Content-Type' => 'text/csv']);
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
