<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesReports\Pages;

use App\Enums\SalesPermission;
use App\Enums\SalesReportType;
use App\Filament\Resources\SalesReports\SalesReportResource;
use App\Models\User;
use App\Reporting\SalesReportPresenter;
use App\Services\Sales\SalesReportFormatter;
use App\Services\Sales\SalesReportService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ViewSalesReports extends Page
{
    protected static string $resource = SalesReportResource::class;

    protected string $view = 'filament.resources.sales-reports.pages.view-sales-reports';

    #[Url]
    public string $reportType = 'quotation_funnel';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    public function mount(): void
    {
        $this->authorizeReportAccess();
    }

    #[\Override]
    public function getTitle(): string
    {
        return __('admin.resources.sales_reports');
    }

    /** @return list<array{value:string,label:string}> */
    public function reportTypeOptions(): array
    {
        return array_map(
            static fn (SalesReportType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
            ],
            SalesReportType::cases(),
        );
    }

    public function reportDescription(): string
    {
        return __('reporting.reports.sales.'.$this->type()->value);
    }

    public function usesAsOfDate(): bool
    {
        return in_array($this->type(), [
            SalesReportType::DeliveredNotInvoiced,
            SalesReportType::InvoicedNotCollected,
            SalesReportType::ReturnsWithoutCredit,
            SalesReportType::CustomerRevenue,
        ], true);
    }

    public function clearFilters(): void
    {
        $this->from = null;
        $this->to = null;
    }

    /** @return array<string,mixed> */
    #[\Override]
    public function getViewData(): array
    {
        $report = $this->reportData();

        return [
            'selectedType' => $this->type(),
            'presentation' => app(SalesReportPresenter::class)->present($this->type(), $report),
        ];
    }

    /** @return array<string,mixed> */
    public function reportData(): array
    {
        $this->authorizeReportAccess();

        return app(SalesReportService::class)->report(
            $this->type(),
            $this->parseDate($this->from),
            $this->parseDate($this->to),
        );
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_csv')
                ->label(__('reporting.actions.export_current'))
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $this->canExport())
                ->authorize(fn (): bool => $this->canExport())
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless($this->canExport(), 403);

        $type = $this->type();
        $csv = app(SalesReportFormatter::class)->toCsv($type, $this->reportData());

        return response()->streamDownload(
            static function () use ($csv): void {
                echo $csv;
            },
            'sales-'.$type->value.'-'.now()->format('Ymd-His').'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    private function canExport(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && $actor->can(SalesPermission::Export->value)
            && app(SalesReportService::class)->canView($actor, $this->type());
    }

    private function authorizeReportAccess(): void
    {
        $actor = auth()->user();

        abort_unless(
            $actor instanceof User && app(SalesReportService::class)->canView($actor, $this->type()),
            403,
        );
    }

    private function type(): SalesReportType
    {
        return SalesReportType::tryFrom($this->reportType) ?? SalesReportType::QuotationFunnel;
    }

    private function parseDate(?string $value): ?CarbonInterface
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
