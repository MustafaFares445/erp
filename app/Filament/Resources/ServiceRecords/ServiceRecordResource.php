<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceRecords;

use App\Filament\Resources\MaintenanceRequests\RelationManagers\ServiceRecordsRelationManager;
use App\Filament\Resources\ServiceRecords\Pages\EditServiceRecord;
use App\Filament\Resources\ServiceRecords\Pages\ListServiceRecords;
use App\Filament\Resources\ServiceRecords\Pages\ViewServiceRecord;
use App\Filament\Resources\ServiceRecords\RelationManagers\ConsumedPartsRelationManager;
use App\Filament\Resources\ServiceRecords\Schemas\ServiceRecordForm;
use App\Filament\Resources\ServiceRecords\Schemas\ServiceRecordInfolist;
use App\Filament\Resources\ServiceRecords\Tables\ServiceRecordsTable;
use App\Models\MaintenanceTask;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Standalone list/view for cross-request search (FR-090) — creation only
 * happens through a maintenance request's own
 * {@see ServiceRecordsRelationManager},
 * so no `create` page exists here.
 */
final class ServiceRecordResource extends Resource
{
    protected static ?string $model = MaintenanceTask::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.support';

    protected static ?int $navigationSort = 703;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.service_records');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return ServiceRecordForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return ServiceRecordInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return ServiceRecordsTable::configure($table);
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListServiceRecords::route('/'),
            'view' => ViewServiceRecord::route('/{record}'),
            'edit' => EditServiceRecord::route('/{record}/edit'),
        ];
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            ConsumedPartsRelationManager::class,
        ];
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['maintenanceRecord:id,customer_id', 'employee.user:id,name'])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
