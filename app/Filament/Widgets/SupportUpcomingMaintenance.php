<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\OccurrenceStatus;
use App\Enums\SupportPermission;
use App\Filament\Resources\MaintenanceSchedules\MaintenanceScheduleResource;
use App\Filament\Widgets\Concerns\BuildsDashboardTables;
use App\Filament\Widgets\Concerns\InteractsWithDashboardFilters;
use App\Models\MaintenanceScheduleOccurrence;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scheduled preventive maintenance that is missed or due within the next
 * fourteen days, soonest first.
 */
final class SupportUpcomingMaintenance extends TableWidget
{
    protected static bool $isLazy = false;

    use BuildsDashboardTables;
    use InteractsWithDashboardFilters;

    protected int|string|array $columnSpan = 'full';

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(SupportPermission::TicketView->value) ?? false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $this->dashboardTable($table)
            ->heading(__('dashboards.support.tables.upcoming_maintenance'))
            ->query(fn (): Builder => MaintenanceScheduleOccurrence::query()
                ->whereIn('status', [OccurrenceStatus::Pending->value, OccurrenceStatus::Missed->value])
                ->where('due_on', '<=', now()->addDays(14)->toDateString())
                ->with(['schedule.customer', 'schedule.serializedInventoryUnit']))
            ->defaultSort('due_on')
            ->recordUrl(static fn (MaintenanceScheduleOccurrence $record): ?string => $record->schedule === null
                ? null
                : MaintenanceScheduleResource::getUrl('view', ['record' => $record->schedule]))
            ->columns([
                TextColumn::make('schedule.schedule_number')
                    ->label(__('dashboards.support.columns.schedule'))
                    ->description(static fn (MaintenanceScheduleOccurrence $record): ?string => $record->schedule?->name)
                    ->weight('medium'),
                TextColumn::make('schedule.customer.company_name')
                    ->label(__('dashboards.support.columns.customer')),
                TextColumn::make('schedule.serializedInventoryUnit.serial_number')
                    ->label(__('dashboards.support.columns.serial'))
                    ->placeholder('—'),
                TextColumn::make('due_on')
                    ->label(__('dashboards.support.columns.due'))
                    ->date()
                    ->color(static fn (MaintenanceScheduleOccurrence $record): string => $record->status === OccurrenceStatus::Missed ? 'danger' : 'gray'),
                TextColumn::make('status')
                    ->label(__('dashboards.support.columns.status'))
                    ->badge(),
            ]);
    }
}
