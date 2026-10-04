<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\CalibrationResult;
use App\Enums\MaintenanceKind;
use App\Enums\SupportPermission;
use App\Filament\Resources\SupportEquipment\SupportEquipmentResource;
use App\Filament\Widgets\Concerns\BuildsDashboardTables;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\SerializedInventoryUnit;
use Carbon\Carbon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Operational calibration queue (not a headline KPI): equipment whose
 * calibration is due soon or overdue on its schedule, or whose latest
 * calibration failed and has not been superseded by a passing one.
 */
final class SupportCalibrationQueue extends TableWidget
{
    protected static bool $isLazy = false;

    use BuildsDashboardTables;
    use InteractsWithDashboardFilters;

    public const int DueSoonDays = 30;

    protected int|string|array $columnSpan = 'full';

    #[\Override]
    public static function canView(): bool
    {
        return (bool) config('support.calibration_enabled', true)
            && (auth()->user()?->can(SupportPermission::CalibrationView->value) ?? false);
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $this->dashboardTable($table)
            ->heading(__('dashboards.support.tables.calibration_queue'))
            ->query(fn (): Builder => $this->queue())
            ->defaultSort('calibration_due_on')
            ->recordUrl(static fn (SerializedInventoryUnit $record): string => SupportEquipmentResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('serial_number')
                    ->label(__('dashboards.support.columns.serial'))
                    ->weight('medium')
                    ->searchable(),
                TextColumn::make('productVariant.name')
                    ->label(__('dashboards.support.columns.product'))
                    ->placeholder('—'),
                TextColumn::make('calibration_due_on')
                    ->label(__('dashboards.support.columns.due'))
                    ->date()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('calibration_state')
                    ->label(__('dashboards.support.columns.status'))
                    ->badge()
                    ->state(static fn (SerializedInventoryUnit $record): string => self::stateOf($record))
                    ->color(static fn (SerializedInventoryUnit $record): string => match (self::stateOf($record)) {
                        'failed' => 'danger',
                        'overdue' => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(static fn (string $state): string => __('dashboards.support.calibration_states.'.$state)),
            ]);
    }

    /** @return Builder<SerializedInventoryUnit> */
    private function queue(): Builder
    {
        $dueSoon = static fn (Builder $schedules): Builder => $schedules
            ->where('maintenance_kind', MaintenanceKind::Calibration->value)
            ->where('is_active', true)
            ->whereDate('next_due_on', '<=', now()->addDays(self::DueSoonDays)->toDateString());

        $unresolvedFailure = static fn (Builder $calibrations): Builder => $calibrations
            ->where('result', CalibrationResult::Failed->value)
            ->whereNotExists(static fn (QueryBuilder $later): QueryBuilder => $later
                ->select(DB::raw('1'))
                ->from('equipment_calibrations as later')
                ->whereColumn('later.serialized_inventory_unit_id', 'equipment_calibrations.serialized_inventory_unit_id')
                ->whereColumn('later.id', '>', 'equipment_calibrations.id')
                ->whereNotNull('later.result'));

        return SerializedInventoryUnit::query()
            ->with('productVariant:id,name')
            ->withMin(['maintenanceSchedules as calibration_due_on' => static fn (Builder $schedules): Builder => $schedules
                ->where('maintenance_kind', MaintenanceKind::Calibration->value)
                ->where('is_active', true)], 'next_due_on')
            ->withExists(['calibrations as has_failed_calibration' => $unresolvedFailure])
            ->where(static fn (Builder $query): Builder => $query
                ->whereHas('maintenanceSchedules', $dueSoon)
                ->orWhereHas('calibrations', $unresolvedFailure));
    }

    private static function stateOf(SerializedInventoryUnit $record): string
    {
        if ((bool) $record->getAttribute('has_failed_calibration')) {
            return 'failed';
        }

        $due = $record->getAttribute('calibration_due_on');

        return is_string($due) && Carbon::parse($due)->lt(today()) ? 'overdue' : 'due_soon';
    }
}
