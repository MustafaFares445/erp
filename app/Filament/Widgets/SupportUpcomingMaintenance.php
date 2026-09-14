<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\OccurrenceStatus;
use App\Enums\SupportPermission;
use App\Filament\Resources\MaintenanceSchedules\MaintenanceScheduleResource;
use App\Models\MaintenanceScheduleOccurrence;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

final class SupportUpcomingMaintenance extends TableWidget
{
    protected static ?string $heading = 'Upcoming maintenance';

    #[\Override]
    public static function canView(): bool
    {
        return auth()->user()?->can(SupportPermission::TicketView->value) ?? false;
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->query(
                MaintenanceScheduleOccurrence::query()
                    ->whereIn('status', [OccurrenceStatus::Pending->value, OccurrenceStatus::Missed->value])
                    ->where('due_on', '<=', now()->addDays(14)->toDateString())
                    ->with(['schedule.customer', 'schedule.serializedInventoryUnit']),
            )
            ->defaultSort('due_on')
            ->recordUrl(static fn (MaintenanceScheduleOccurrence $record): ?string => $record->schedule === null
                ? null
                : MaintenanceScheduleResource::getUrl('view', ['record' => $record->schedule]))
            ->columns([
                TextColumn::make('schedule.schedule_number')->label('Schedule')->badge(),
                TextColumn::make('schedule.customer.company_name')->label('Customer'),
                TextColumn::make('schedule.name')->label('Maintenance'),
                TextColumn::make('schedule.serializedInventoryUnit.serial_number')->label('Serial')->placeholder('—'),
                TextColumn::make('due_on')->label('Due')->date(),
                TextColumn::make('status')->badge(),
            ])
            ->paginated([5, 10]);
    }
}
