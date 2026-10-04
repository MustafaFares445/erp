<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\ExternalRepairStatus;
use App\Enums\SupportPermission;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Widgets\Concerns\BuildsDashboardTables;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\MaintenanceExternalRepair;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** Operational queue (not a headline KPI): equipment waiting for a supplier repair or replacement. */
final class SupportSupplierRepairs extends TableWidget
{
    protected static bool $isLazy = false;

    use BuildsDashboardTables;
    use InteractsWithDashboardFilters;

    #[\Override]
    public static function canView(): bool
    {
        return (bool) config('support.external_repair_enabled', false)
            && (auth()->user()?->can(SupportPermission::RmaView->value) ?? false);
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $this->dashboardTable($table)
            ->heading(__('dashboards.support.tables.supplier_repairs'))
            ->query(fn (): Builder => MaintenanceExternalRepair::query()
                ->whereIn('status', ExternalRepairStatus::openValues())
                ->with(['supplier', 'serializedInventoryUnit']))
            ->defaultSort('requested_at')
            ->recordUrl(static fn (MaintenanceExternalRepair $record): string => MaintenanceRequestResource::getUrl('view', ['record' => $record->maintenance_record_id]))
            ->columns([
                TextColumn::make('serializedInventoryUnit.serial_number')->label(__('dashboards.support.columns.serial'))->weight('medium'),
                TextColumn::make('supplier.name')->label(__('dashboards.support.columns.supplier')),
                TextColumn::make('status')->label(__('dashboards.support.columns.status'))->badge(),
                TextColumn::make('estimated_return_on')
                    ->label(__('dashboards.support.columns.due'))
                    ->date()
                    ->placeholder('—')
                    ->color(static fn (MaintenanceExternalRepair $record): string => $record->isOverdue() ? 'danger' : 'gray'),
            ]);
    }
}
