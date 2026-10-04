<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceRequests;

use App\Filament\LocalizedResource as Resource;
use App\Filament\RelationManagers\CollaborationEntriesRelationManager;
use App\Filament\RelationManagers\CustomFieldsRelationManager;
use App\Filament\Resources\MaintenanceRequests\Pages\CreateMaintenanceRequest;
use App\Filament\Resources\MaintenanceRequests\Pages\EditMaintenanceRequest;
use App\Filament\Resources\MaintenanceRequests\Pages\ListMaintenanceRequests;
use App\Filament\Resources\MaintenanceRequests\Pages\ViewMaintenanceRequest;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\CalibrationRelationManager;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\ExternalRepairRelationManager;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\InstallationRelationManager;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\LabourEntriesRelationManager;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\LoanRelationManager;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\ServiceRecordsRelationManager;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\ThirdPartyCostsRelationManager;
use App\Filament\Resources\MaintenanceRequests\Schemas\MaintenanceRequestForm;
use App\Filament\Resources\MaintenanceRequests\Schemas\MaintenanceRequestInfolist;
use App\Filament\Resources\MaintenanceRequests\Tables\MaintenanceRequestsTable;
use App\Models\MaintenanceRecord;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

final class MaintenanceRequestResource extends Resource
{
    protected static ?string $model = MaintenanceRecord::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrench;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.support';

    protected static ?int $navigationSort = 702;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.maintenance_requests');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return MaintenanceRequestForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return MaintenanceRequestInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return MaintenanceRequestsTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListMaintenanceRequests::route('/'),
            'create' => CreateMaintenanceRequest::route('/create'),
            'view' => ViewMaintenanceRequest::route('/{record}'),
            'edit' => EditMaintenanceRequest::route('/{record}/edit'),
        ];
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            CollaborationEntriesRelationManager::class,
            CustomFieldsRelationManager::class,
            InstallationRelationManager::class,
            CalibrationRelationManager::class,
            LoanRelationManager::class,
            ExternalRepairRelationManager::class,
            ServiceRecordsRelationManager::class,
            LabourEntriesRelationManager::class,
            ThirdPartyCostsRelationManager::class,
        ];
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'customer:id,company_name',
                'ticket:id,ticket_number',
                'serializedInventoryUnit.productVariant:id,name',
                'scheduleOccurrence.schedule:id,schedule_number',
            ])
            ->withSum('coverageLines as coverage_total_amount_minor', 'amount_minor')
            ->withSum('coverageLines as coverage_covered_amount_minor', 'covered_amount_minor')
            ->withSum('coverageLines as coverage_customer_amount_minor', 'customer_amount_minor')
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
