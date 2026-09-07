<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesReports\Pages;

use App\Enums\SalesPermission;
use App\Enums\SalesReportType;
use App\Filament\Resources\SalesReports\SalesReportResource;
use App\Models\User;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Sales\SalesReportFormatter;
use App\Services\Sales\SalesReportService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The report-type selector, its date filters, and the dispatch to
 * {@see SalesReportService} for whichever of the nine
 * {@see SalesReportType} cases is currently selected.
 *
 * This page renders exactly what {@see SalesReportService} returns — it
 * never recomputes, corrects, or hides a figure on the way to the screen.
 * `InvoicedNotCollected` in particular is displayed as-is: it is a direct
 * delegation to {@see AccountsReceivableService::aging()},
 * so nothing here may reconcile or plug a difference the AR module itself
 * has not already resolved.
 *
 * The permission is re-checked on the streaming export method itself, not
 * only on the button's `visible()`/`authorize()` closures — an export
 * guarded only by its button's visibility is not guarded, because the
 * request can be issued directly against the Livewire component.
 */
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
        return 'Sales reports';
    }

    /** @return list<array{value: string, label: string}> */
    public function reportTypeOptions(): array
    {
        return array_map(
            static fn (SalesReportType $type): array => ['value' => $type->value, 'label' => $type->label()],
            SalesReportType::cases(),
        );
    }

    /** @return array<string, mixed> */
    public function reportData(): array
    {
        $this->authorizeReportAccess();

        return app(SalesReportService::class)->report($this->type(), $this->parseDate($this->from), $this->parseDate($this->to));
    }

    /**
     * Every top-level scalar (or null) field in the report — the aggregate
     * figures every report type carries alongside its detail rows, if any.
     *
     * @return array<string, bool|float|int|string|null>
     */
    public function summaryFields(): array
    {
        $summary = [];

        foreach ($this->reportData() as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $summary[$key] = $value;
            }
        }

        return $summary;
    }

    /**
     * Every top-level key whose value is a non-empty list of associative
     * arrays, rendered as its own sub-table. This is how one page hosts nine
     * differently-shaped reports without nine bespoke templates: whatever
     * list of rows a report produces is shown, unmodified, under its own
     * heading.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function tableSections(): array
    {
        $sections = [];

        foreach ($this->reportData() as $key => $value) {
            if (! is_array($value)) {
                continue;
            }
            if ($value === []) {
                continue;
            }
            $first = $value[array_key_first($value)] ?? null;

            if (is_array($first)) {
                /** @var list<array<string, mixed>> $value */
                $sections[$key] = $value;
            }
        }

        return $sections;
    }

    /**
     * Explicit empty state: whether the selected report has zero detail
     * rows for the selected period. A zero-valued aggregate (e.g. "Total: 0")
     * is a legitimate computed summary, not an empty report — this flag is
     * about there being no rows to list, never about hiding a real figure.
     */
    public function hasNoDetailRows(): bool
    {
        return $this->tableSections() === [];
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_csv')
                ->label('Export CSV')
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
            ['Content-Type' => 'text/csv'],
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
