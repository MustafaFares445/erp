<?php

declare(strict_types=1);

namespace App\Filament\Resources\CrmReports\Pages;

use App\Enums\CrmReportType;
use App\Filament\Resources\CrmReports\CrmReportResource;
use App\Services\Crm\CrmFunnelReportService;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ViewCrmReports extends Page
{
    protected static string $resource = CrmReportResource::class;

    protected string $view = 'filament.resources.crm-reports.pages.view-crm-reports';

    public string $reportType = 'leads_by_source';

    /** @return array<string, string> */
    public function reportOptions(): array
    {
        return collect(CrmReportType::cases())
            ->mapWithKeys(fn (CrmReportType $type): array => [$type->value => $type->label()])
            ->all();
    }

    /** @return Collection<int, array<string, bool|float|int|string|null>> */
    public function rows(): Collection
    {
        $service = app(CrmFunnelReportService::class);
        $type = CrmReportType::from($this->reportType);

        /** @var list<array<string, bool|float|int|string|null>> $rows */
        $rows = match ($type) {
            CrmReportType::LeadsBySource => $service->bySource()->map(static fn (array $row): array => [
                'Source' => $row['source'],
                'Leads' => $row['lead_count'],
                'Converted' => $row['converted_count'],
            ])->values()->all(),
            CrmReportType::StageConversion => $service->byStage()->map(static fn (array $row): array => [
                'Stage' => $row['status'],
                'Leads' => $row['lead_count'],
            ])->values()->all(),
            CrmReportType::CampaignPerformance => $service->byCampaign()->map(static fn (array $campaign): array => [
                'Campaign' => $campaign['campaign_number'].' · '.$campaign['name'],
                'Recipients' => $campaign['recipients_count'],
                'Interested' => $campaign['interested_count'],
                'Attributed leads' => $campaign['leads_count'],
            ])->values()->all(),
            CrmReportType::PipelineValueAndAge => $service->pipelineAge()->map(static fn (array $row): array => [
                'Stage' => $row['status'],
                'Open leads' => $row['lead_count'],
                'Average age (days)' => round($row['average_age_days'], 1),
                'Pipeline value' => 'Available after WP-2.2 opportunity linkage',
            ])->values()->all(),
            CrmReportType::AttributedRevenue => $service->attributedRevenue()->map(static fn (array $row): array => [
                'Campaign ID' => $row['campaign_id'],
                'Collected revenue' => number_format($row['collected_amount'], 2, '.', ''),
            ])->values()->all(),
        };

        return collect($rows);
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_csv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        $rows = $this->rows();

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            $first = $rows->first();

            if (is_array($first)) {
                fputcsv($handle, array_keys($first), escape: '\\');
            }

            foreach ($rows as $row) {
                fputcsv($handle, array_values($row), escape: '\\');
            }

            fclose($handle);
        }, 'crm-'.$this->reportType.'-'.now()->format('Ymd-His').'.csv');
    }
}
