<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryReports\Pages;

use App\Enums\InventoryPermission;
use App\Enums\InventoryReportType;
use App\Enums\ReconciliationScope;
use App\Filament\Resources\InventoryReports\InventoryReportResource;
use App\Filament\Resources\InventoryReports\Tables\InventoryReportsTable;
use App\Filament\Resources\InventoryReports\Widgets\InventoryQuarantineAgeing;
use App\Filament\Resources\InventoryReports\Widgets\ReconciliationStatus;
use App\Models\User;
use App\Services\Inventory\InventoryReportFormatter;
use App\Services\Inventory\InventoryReportService;
use App\Services\Inventory\ReconciliationReportService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ManageInventoryReports extends ManageRecords
{
    protected static string $resource = InventoryReportResource::class;

    #[Url]
    public ?string $report = null;

    #[\Override]
    public function getSubheading(): string
    {
        return __('reporting.reports.inventory.'.$this->reportType()->value);
    }

    #[\Override]
    public function table(Table $table): Table
    {
        if ($this->isReport(InventoryReportType::Reconciliation)) {
            return $this->reconciliationTable($table);
        }

        return InventoryReportsTable::configure($table, $this->reportType(), $this->canViewPricing());
    }

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        $tabs = [];

        foreach ($this->categoryMap() as $category => $metadata) {
            if ($this->reportsForCategory($category) === []) {
                continue;
            }

            $tabs[$category] = Tab::make(__($metadata['label']));
        }

        return $tabs;
    }

    #[\Override]
    public function getDefaultActiveTab(): string
    {
        return $this->categoryForReport($this->reportType());
    }

    /** @return Builder<covariant \Illuminate\Database\Eloquent\Model> */
    #[\Override]
    protected function getTableQuery(): Builder
    {
        if ($this->isReport(InventoryReportType::Reconciliation)) {
            return app(ReconciliationReportService::class)->query($this->reportFilters());
        }

        return app(InventoryReportService::class)->query($this->reportType(), $this->reportFilters());
    }

    public function updatedReport(): void
    {
        $requested = is_string($this->report) ? InventoryReportType::tryFrom($this->report) : null;

        if (! $requested instanceof InventoryReportType || ! in_array($requested, $this->availableReports(), true)) {
            $this->report = $this->availableReports()[0]->value ?? null;
            $requested = $this->report !== null ? InventoryReportType::tryFrom($this->report) : null;
        }

        if ($requested instanceof InventoryReportType) {
            $this->activeTab = $this->categoryForReport($requested);
        }

        $this->tableFilters = null;
        $this->resetTable();
    }

    #[\Override]
    public function updatedActiveTab(): void
    {
        $category = is_string($this->activeTab) ? $this->activeTab : null;
        $reports = $category !== null ? $this->reportsForCategory($category) : [];

        if ($reports !== [] && ! in_array($this->reportType(), $reports, true)) {
            $this->report = $reports[0]->value;
        }

        $this->tableFilters = null;
        $this->resetTable();
    }

    public function isReport(InventoryReportType ...$types): bool
    {
        return in_array($this->reportType(), $types, true);
    }

    public function canViewPricing(): bool
    {
        return auth()->user()?->can(InventoryPermission::PricingView->value) ?? false;
    }

    /** @return list<InventoryReportType> */
    private function availableReports(): array
    {
        $actor = auth()->user();

        return $actor instanceof User
            ? app(InventoryReportService::class)->availableReports($actor)
            : [];
    }

    private function reportType(): InventoryReportType
    {
        $available = $this->availableReports();
        $requested = is_string($this->report)
            ? InventoryReportType::tryFrom($this->report)
            : null;

        if ($requested instanceof InventoryReportType && in_array($requested, $available, true)) {
            return $requested;
        }

        return $available[0] ?? InventoryReportType::Catalog;
    }

    /**
     * @return array<string,array{label:string,reports:list<InventoryReportType>}>
     */
    private function categoryMap(): array
    {
        return [
            'stock_availability' => [
                'label' => 'reporting.categories.stock_availability',
                'reports' => [
                    InventoryReportType::StockLevels,
                    InventoryReportType::Devices,
                    InventoryReportType::ExpiryLots,
                    InventoryReportType::QuarantineAgeing,
                ],
            ],
            'movements_control' => [
                'label' => 'reporting.categories.movements_control',
                'reports' => [
                    InventoryReportType::Movements,
                    InventoryReportType::ConditionChanges,
                    InventoryReportType::CountVariance,
                    InventoryReportType::Reconciliation,
                ],
            ],
            'catalog_suppliers' => [
                'label' => 'reporting.categories.catalog_suppliers',
                'reports' => [
                    InventoryReportType::Catalog,
                    InventoryReportType::SupplierComparison,
                ],
            ],
            'pricing' => [
                'label' => 'reporting.categories.pricing',
                'reports' => [
                    InventoryReportType::PriceHistory,
                    InventoryReportType::PricingTiers,
                    InventoryReportType::CustomerAssignments,
                    InventoryReportType::FloorOverrides,
                ],
            ],
            'imports' => [
                'label' => 'reporting.categories.imports',
                'reports' => [
                    InventoryReportType::ImportRuns,
                    InventoryReportType::ImportResults,
                ],
            ],
        ];
    }

    /** @return list<InventoryReportType> */
    private function reportsForCategory(string $category): array
    {
        $configured = $this->categoryMap()[$category]['reports'] ?? [];
        $available = $this->availableReports();

        return array_values(array_filter(
            $configured,
            static fn (InventoryReportType $type): bool => in_array($type, $available, true),
        ));
    }

    private function categoryForReport(InventoryReportType $type): string
    {
        return array_find_key(
            $this->categoryMap(),
            static fn (array $metadata): bool => in_array($type, $metadata['reports'], true),
        ) ?? 'catalog_suppliers';
    }

    private function selectReport(InventoryReportType $type): void
    {
        abort_unless(in_array($type, $this->availableReports(), true), 403);

        $this->report = $type->value;
        $this->activeTab = $this->categoryForReport($type);
        $this->tableFilters = null;
        $this->resetTable();
    }

    /** @return array<string, mixed> */
    private function reportFilters(): array
    {
        $filters = [];

        foreach ($this->tableFilters ?? [] as $name => $state) {
            if (! is_array($state)) {
                continue;
            }

            if (array_key_exists('value', $state)) {
                $filters[$name] = $state['value'];

                continue;
            }

            foreach ($state as $key => $value) {
                if (is_string($key)) {
                    $filters[$key] = $value;
                }
            }
        }

        return $filters;
    }

    /**
     * A summary card above the reports whose table does not answer the
     * headline question by itself: the latest reconciliation verdict, and
     * quarantine ageing for stock the lot-grain report cannot see.
     */
    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return match ($this->reportType()) {
            InventoryReportType::Reconciliation => [ReconciliationStatus::class],
            InventoryReportType::QuarantineAgeing => [InventoryQuarantineAgeing::class],
            default => [],
        };
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        $reportActions = array_map(
            fn (InventoryReportType $type): Action => Action::make('select_report_'.$type->value)
                ->label($type->label())
                ->icon($this->reportType() === $type ? 'heroicon-m-check' : null)
                ->action(function () use ($type): void {
                    $this->selectReport($type);
                }),
            $this->reportsForCategory(is_string($this->activeTab) ? $this->activeTab : $this->categoryForReport($this->reportType())),
        );

        return [
            ActionGroup::make($reportActions)
                ->label(__('Report').': '.$this->reportType()->label())
                ->icon('heroicon-o-document-chart-bar')
                ->color('gray'),
            Action::make('export_current_report')
                ->label(__('reporting.actions.export_current'))
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $this->canExportCurrentReport())
                ->authorize(fn (): bool => $this->canExportCurrentReport())
                ->action(fn (): StreamedResponse => $this->exportCurrentReport()),
        ];
    }

    private function reconciliationTable(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('started_at')->label(__('admin.inventory.reports.reconciliation.started'))->dateTime()->sortable(),
                TextColumn::make('scope')
                    ->label(__('admin.inventory.reports.reconciliation.scope'))
                    ->badge()
                    ->formatStateUsing(static fn (ReconciliationScope|string|null $state): string => $state instanceof ReconciliationScope
                        ? __('admin.inventory.reports.reconciliation.scopes.'.$state->value)
                        : (is_string($state) ? __('admin.inventory.reports.reconciliation.scopes.'.$state) : '—')),
                TextColumn::make('invariant')->label(__('admin.inventory.reports.reconciliation.invariant'))->searchable()->wrap(),
                IconColumn::make('passed')->label(__('admin.inventory.reports.reconciliation.passed'))->boolean(),
                TextColumn::make('divergence_count')->label(__('admin.inventory.reports.reconciliation.divergences'))->numeric()->sortable(),
                TextColumn::make('detail')
                    ->label(__('admin.inventory.reports.reconciliation.diagnostics'))
                    ->formatStateUsing(static function (mixed $state): string {
                        if (! is_array($state) || $state === []) {
                            return '—';
                        }

                        return implode("\n", array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : (json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''), $state));
                    })
                    ->wrap()
                    ->limit(160),
                TextColumn::make('trigger_source')->label(__('admin.inventory.reports.reconciliation.trigger'))->badge(),
                TextColumn::make('triggeredBy.name')->label(__('admin.inventory.reports.reconciliation.triggered_by'))->placeholder(__('admin.inventory.reports.reconciliation.system')),
                TextColumn::make('finished_at')->label(__('admin.inventory.reports.reconciliation.finished'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('scope')
                    ->options(collect(ReconciliationScope::cases())->mapWithKeys(static fn (ReconciliationScope $scope): array => [
                        $scope->value => __('admin.inventory.reports.reconciliation.scopes.'.$scope->value),
                    ])->all())
                    ->query(static fn (Builder $query): Builder => $query),
                TernaryFilter::make('passed')->label(__('admin.inventory.reports.reconciliation.verdict'))->query(static fn (Builder $query): Builder => $query),
                SelectFilter::make('trigger_source')
                    ->options([
                        'manual' => __('admin.inventory.reports.reconciliation.manual'),
                        'schedule' => __('admin.inventory.reports.reconciliation.scheduled'),
                        'period_close' => __('admin.inventory.reports.reconciliation.period_close'),
                    ])
                    ->query(static fn (Builder $query): Builder => $query),
                Filter::make('date_range')
                    ->schema([
                        DatePicker::make('from')->label(__('admin.inventory.reports.reconciliation.from')),
                        DatePicker::make('until')->label(__('admin.inventory.reports.reconciliation.until')),
                    ])
                    ->query(static fn (Builder $query): Builder => $query),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading(__('admin.inventory.reports.reconciliation.never_run'))
            ->emptyStateDescription(__('admin.inventory.reports.reconciliation.never_run_description'))
            ->recordActions([])
            ->toolbarActions([]);
    }

    public function exportCurrentReport(): StreamedResponse
    {
        abort_unless($this->canExportCurrentReport(), 403);

        $type = $this->reportType();
        $filters = $this->reportFilters();
        $includePricing = $this->canViewPricing();
        $formatter = app(InventoryReportFormatter::class);

        return response()->streamDownload(function () use ($type, $filters, $includePricing, $formatter): void {
            /** @var resource $handle */
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, $formatter->headings($type, $includePricing), escape: '\\');
            app(InventoryReportService::class)->query($type, $filters)->chunkById(500, function (Collection $records) use ($handle, $formatter, $type, $includePricing): void {
                foreach ($records as $record) {
                    fputcsv($handle, $formatter->values($type, $record, $includePricing), escape: '\\');
                }
            });
            fclose($handle);
        }, 'inventory-'.$type->value.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function canExportCurrentReport(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && $actor->can(InventoryPermission::Export->value)
            && app(InventoryReportService::class)->canView($actor, $this->reportType());
    }
}
