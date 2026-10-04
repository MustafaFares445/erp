<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportEquipment;

use App\Enums\MaintenanceStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\SupportPermission;
use App\Enums\TicketStatus;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\SupportEquipment\Pages\ListSupportEquipment;
use App\Filament\Resources\SupportEquipment\Pages\ViewSupportEquipment;
use App\Filament\Resources\SupportEquipment\Tables\SupportEquipmentTable;
use App\Models\SerializedInventoryUnit;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

final class SupportEquipmentResource extends Resource
{
    protected static ?string $model = SerializedInventoryUnit::class;

    protected static ?string $recordTitleAttribute = 'serial_number';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.support';

    protected static ?int $navigationSort = 705;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.equipment_360');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.equipment_unit');
    }

    #[\Override]
    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.equipment_360');
    }

    #[\Override]
    public static function canViewAny(): bool
    {
        return auth()->user()?->can(SupportPermission::Equipment360View->value) ?? false;
    }

    #[\Override]
    public static function canView(Model $record): bool
    {
        return self::canViewAny() && $record instanceof SerializedInventoryUnit;
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    #[\Override]
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return SupportEquipmentTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSupportEquipment::route('/'),
            'view' => ViewSupportEquipment::route('/{record}'),
        ];
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where(function (Builder $query): void {
                $query->where('custody_type', SerializedCustodyType::Customer->value)
                    ->orWhereHas('tickets')
                    ->orWhereHas('maintenanceRecords')
                    ->orWhereHas('supportEntitlements');
            })
            ->with([
                'productVariant.product:id,name',
                'currentWarrantyEntitlement',
            ])
            ->withCount([
                'tickets as active_tickets_count' => static fn (Builder $query): Builder => $query->whereNotIn('status', [
                    TicketStatus::Resolved->value,
                    TicketStatus::Closed->value,
                    TicketStatus::Cancelled->value,
                ]),
                'maintenanceRecords as active_maintenance_count' => static fn (Builder $query): Builder => $query->whereNotIn('status', [
                    MaintenanceStatus::Closed->value,
                    MaintenanceStatus::Cancelled->value,
                ]),
            ])
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
