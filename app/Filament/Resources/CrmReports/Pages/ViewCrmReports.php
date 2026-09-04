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

    /** @return Collection<int, array<string, mixed>> */
    public function rows(): Collection
    {
        $service = app(CrmFunnelReportService::class);
        $type = CrmReportType::from($this->reportType);

        return (match ($type) {
            CrmReportType::LeadsBySource => $service->bySource()->map(fn ($row): array => [
                'Source' => $this->value($row->source),
                'Leads' => (int) $row->lead_count,
                'Converted' => (int) $row->converted_count,
            ]),
            CrmReportType::StageConversion => $service->byStage()->map(fn ($row): array => [
                'Stage' => $this->value($row->status),
                'Leads' => (int) $row->lead_count,
            ]),
            CrmReportType::CampaignPerformance => $service->byCampaign()->map(fn ($campaign): array => [
                'Campaign' => $campaign->campaign_number.' · '.$campaign->name,
                'Recipients' => (int) $campaign->recipients_count,
                'Interested' => (int) $campaign->interested_count,
                'Attributed leads' => (int) $campaign->leads_count,
            ]),
            CrmReportType::PipelineValueAndAge => $service->pipelineAge()->map(fn ($row): array => [
                'Stage' => $this->value($row->status),
                'Open leads' => (int) $row->lead_count,
                'Average age (days)' => round((float) $row->average_age_days, 1),
                'Pipeline value' => 'Available after WP-2.2 opportunity linkage',
            ]),
            CrmReportType::AttributedRevenue => $service->attributedRevenue()->map(fn ($row): array => [
                'Campaign ID' => (int) $row->campaign_id,
                'Collected revenue' => number_format((float) $row->collected_amount, 2, '.', ''),
            ]),
        })->values();
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
                fputcsv($handle, array_keys($first));
            }

            foreach ($rows as $row) {
                fputcsv($handle, array_values($row));
            }

            fclose($handle);
        }, 'crm-'.$this->reportType.'-'.now()->format('Ymd-His').'.csv');
    }

    private function value(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }
}
