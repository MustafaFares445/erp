<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryReports\Pages;

use App\Enums\InventoryPermission;
use App\Enums\InventoryReportType;
use App\Enums\ReconciliationScope;
use App\Filament\Resources\InventoryReports\InventoryReportResource;
use App\Filament\Resources\InventoryReports\Tables\InventoryReportsTable;
use App\Models\User;
use App\Services\Inventory\InventoryReportFormatter;
use App\Services\Inventory\InventoryReportService;
use App\Services\Inventory\ReconciliationReportService;
use Filament\Actions\Action;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ManageInventoryReports extends ManageRecords
{
    protected static string $resource = InventoryReportResource::class;

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

        foreach ($this->availableReports() as $type) {
            $tabs[$type->value] = Tab::make($type->label());
        }

        return $tabs;
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

    #[\Override]
    public function updatedActiveTab(): void
    {
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
        $requested = is_string($this->activeTab)
            ? InventoryReportType::tryFrom($this->activeTab)
            : null;

        if ($requested instanceof InventoryReportType && in_array($requested, $available, true)) {
            return $requested;
        }

        return $available[0] ?? InventoryReportType::Catalog;
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

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_current_report')
                ->label('Export current report CSV')
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
                TextColumn::make('started_at')->label('Started')->dateTime()->sortable(),
                TextColumn::make('scope')
                    ->label('Scope')
                    ->badge()
                    ->formatStateUsing(static fn (ReconciliationScope|string|null $state): string => $state instanceof ReconciliationScope ? $state->value : (string) $state),
                TextColumn::make('invariant')->label('Invariant')->searchable()->wrap(),
                IconColumn::make('passed')->label('Passed')->boolean(),
                TextColumn::make('divergence_count')->label('Divergences')->numeric()->sortable(),
                TextColumn::make('detail')
                    ->label('Diagnostics')
                    ->formatStateUsing(static function (mixed $state): string {
                        if (! is_array($state) || $state === []) {
                            return '—';
                        }

                        return implode("\n", array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : (json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''), $state));
                    })
                    ->wrap()
                    ->limit(160),
                TextColumn::make('trigger_source')->label('Trigger')->badge(),
                TextColumn::make('triggeredBy.name')->label('Triggered by')->placeholder('System'),
                TextColumn::make('finished_at')->label('Finished')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('scope')
                    ->options(collect(ReconciliationScope::cases())->mapWithKeys(static fn (ReconciliationScope $scope): array => [$scope->value => str($scope->value)->replace('_', ' ')->title()->toString()])->all())
                    ->query(static fn (Builder $query): Builder => $query),
                TernaryFilter::make('passed')->label('Verdict')->query(static fn (Builder $query): Builder => $query),
                SelectFilter::make('trigger_source')
                    ->options(['manual' => 'Manual', 'schedule' => 'Scheduled', 'period_close' => 'Period close'])
                    ->query(static fn (Builder $query): Builder => $query),
                Filter::make('date_range')
                    ->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(static fn (Builder $query): Builder => $query),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Reconciliation has never been run')
            ->emptyStateDescription('Run the reconciliation to persist invariant verdicts and diagnostics before relying on this report.')
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
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, $formatter->headings($type, $includePricing), escape: '\\');
            app(InventoryReportService::class)->query($type, $filters)->chunkById(500, function ($records) use ($handle, $formatter, $type, $includePricing): void {
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
