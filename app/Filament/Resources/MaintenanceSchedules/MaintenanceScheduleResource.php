<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceSchedules;

use App\Filament\Resources\MaintenanceSchedules\Pages\CreateMaintenanceSchedule;
use App\Filament\Resources\MaintenanceSchedules\Pages\EditMaintenanceSchedule;
use App\Filament\Resources\MaintenanceSchedules\Pages\ListMaintenanceSchedules;
use App\Filament\Resources\MaintenanceSchedules\Pages\ViewMaintenanceSchedule;
use App\Filament\Resources\MaintenanceSchedules\RelationManagers\OccurrencesRelationManager;
use App\Filament\Resources\MaintenanceSchedules\Schemas\MaintenanceScheduleForm;
use App\Filament\Resources\MaintenanceSchedules\Schemas\MaintenanceScheduleInfolist;
use App\Filament\Resources\MaintenanceSchedules\Tables\MaintenanceSchedulesTable;
use App\Models\MaintenanceSchedule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class MaintenanceScheduleResource extends Resource
{
    protected static ?string $model = MaintenanceSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.support';

    protected static ?int $navigationSort = 703;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.maintenance_schedules');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return MaintenanceScheduleForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return MaintenanceScheduleInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return MaintenanceSchedulesTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListMaintenanceSchedules::route('/'),
            'create' => CreateMaintenanceSchedule::route('/create'),
            'view' => ViewMaintenanceSchedule::route('/{record}'),
            'edit' => EditMaintenanceSchedule::route('/{record}/edit'),
        ];
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            OccurrencesRelationManager::class,
        ];
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['serializedInventoryUnit:id,serial_number', 'customer:id,company_name']);
    }
}
