<?php

declare(strict_types=1);

namespace App\Filament\Resources\CrmReports\Pages;

use App\Enums\CrmPermission;
use App\Enums\CrmReportType;
use App\Filament\Resources\CrmReports\CrmReportResource;
use App\Models\User;
use App\Services\Crm\CrmFunnelReportService;
use App\Support\MoneyFormatter;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ViewCrmReports extends Page
{
    protected static string $resource = CrmReportResource::class;

    protected string $view = 'filament.resources.crm-reports.pages.view-crm-reports';

    #[Url]
    public string $reportType = 'leads_by_source';

    public function mount(): void
    {
        $this->authorizeReportAccess();
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.crm_reports');
    }

    /** @return array<string, string> */
    public function reportOptions(): array
    {
        return collect(CrmReportType::cases())
            ->mapWithKeys(fn (CrmReportType $type): array => [$type->value => $type->label()])
            ->all();
    }

    public function reportDescription(): string
    {
        return __('reporting.reports.crm.'.$this->type()->value);
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function getViewData(): array
    {
        $rows = $this->rows();

        return [
            'reportRows' => $rows,
            'reportSummary' => $this->summary($rows),
        ];
    }

    /** @return Collection<int, array<string, bool|float|int|string|null>> */
    public function rows(): Collection
    {
        $this->authorizeReportAccess();

        $service = app(CrmFunnelReportService::class);

        /** @var list<array<string, bool|float|int|string|null>> $rows */
        $rows = match ($this->type()) {
            CrmReportType::LeadsBySource => $service->bySource()->map(static fn (array $row): array => [
                __('Source') => $row['source'],
                __('Leads') => $row['lead_count'],
                __('Converted') => $row['converted_count'],
            ])->values()->all(),
            CrmReportType::StageConversion => $service->byStage()->map(static fn (array $row): array => [
                __('Stage') => $row['status'],
                __('Leads') => $row['lead_count'],
            ])->values()->all(),
            CrmReportType::CampaignPerformance => $service->byCampaign()->map(static fn (array $campaign): array => [
                __('Campaign') => $campaign['campaign_number'].' · '.$campaign['name'],
                __('Recipients') => $campaign['recipients_count'],
                __('Interested') => $campaign['interested_count'],
                __('Attributed leads') => $campaign['leads_count'],
                __('Attributed opportunities') => $campaign['opportunities_count'],
            ])->values()->all(),
            CrmReportType::PipelineValueAndAge => $service->pipelineAge()->map(static fn (array $row): array => [
                __('Stage') => __(str($row['stage'])->replace('_', ' ')->headline()->toString()),
                __('Currency') => $row['currency'],
                __('Open opportunities') => $row['opportunity_count'],
                __('Average age (days)') => round($row['average_age_days'], 1),
                __('Pipeline value') => MoneyFormatter::format($row['pipeline_value_minor'], $row['currency']),
            ])->values()->all(),
            CrmReportType::AttributedRevenue => $service->attributedRevenue()->map(static fn (array $row): array => [
                __('Campaign ID') => $row['campaign_id'],
                __('Collected amount') => number_format($row['collected_amount'], 2, '.', ''),
            ])->values()->all(),
        };

        return collect($rows);
    }

    /**
     * @param  Collection<int, array<string, bool|float|int|string|null>>|null  $rows
     * @return list<array{label:string,value:int|float|string}>
     */
    public function summary(?Collection $rows = null): array
    {
        $rows ??= $this->rows();

        return match ($this->type()) {
            CrmReportType::LeadsBySource => [
                ['label' => __('Leads'), 'value' => $this->sumInt($rows, __('Leads'))],
                ['label' => __('Converted'), 'value' => $this->sumInt($rows, __('Converted'))],
                ['label' => __('Sources'), 'value' => $rows->count()],
            ],
            CrmReportType::StageConversion => [
                ['label' => __('Leads'), 'value' => $this->sumInt($rows, __('Leads'))],
                ['label' => __('Stages'), 'value' => $rows->count()],
            ],
            CrmReportType::CampaignPerformance => [
                ['label' => __('Campaigns'), 'value' => $rows->count()],
                ['label' => __('Recipients'), 'value' => $this->sumInt($rows, __('Recipients'))],
                ['label' => __('Interested'), 'value' => $this->sumInt($rows, __('Interested'))],
            ],
            CrmReportType::PipelineValueAndAge => [
                ['label' => __('Open opportunities'), 'value' => $this->sumInt($rows, __('Open opportunities'))],
                ['label' => __('Stage / currency groups'), 'value' => $rows->count()],
            ],
            CrmReportType::AttributedRevenue => [
                ['label' => __('Campaigns with collected revenue'), 'value' => $rows->count()],
            ],
        };
    }

    /**
     * @param  Collection<int, array<string, bool|float|int|string|null>>  $rows
     */
    private function sumInt(Collection $rows, string $key): int
    {
        return $rows->sum(
            static fn (array $row): int => is_numeric($row[$key] ?? null)
                ? (int) $row[$key]
                : 0,
        );
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_csv')
                ->label(__('reporting.actions.export_current'))
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $this->canViewReports())
                ->authorize(fn (): bool => $this->canViewReports())
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        $this->authorizeReportAccess();

        $rows = $this->rows();
        $type = $this->type();

        return response()->streamDownload(function () use ($rows): void {
            /** @var resource $handle */
            $handle = fopen('php://output', 'wb');

            $first = $rows->first();

            if (is_array($first)) {
                fputcsv($handle, array_keys($first), escape: '\\');
            }

            foreach ($rows as $row) {
                fputcsv($handle, array_values($row), escape: '\\');
            }

            fclose($handle);
        }, 'crm-'.$type->value.'-'.now()->format('Ymd-His').'.csv');
    }

    private function authorizeReportAccess(): void
    {
        abort_unless($this->canViewReports(), 403);
    }

    private function canViewReports(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->can(CrmPermission::FunnelReport->value);
    }

    private function type(): CrmReportType
    {
        return CrmReportType::tryFrom($this->reportType) ?? CrmReportType::LeadsBySource;
    }
}
