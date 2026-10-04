<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceAppointments;

use App\Filament\Concerns\RequiresSupportFeature;
use App\Filament\LocalizedResource as Resource;
use App\Filament\Resources\ServiceAppointments\Pages\ListServiceAppointments;
use App\Filament\Resources\ServiceAppointments\Tables\ServiceAppointmentsTable;
use App\Models\ServiceAppointment;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class ServiceAppointmentResource extends Resource
{
    use RequiresSupportFeature;

    protected static function supportFeatureFlag(): string
    {
        return 'support.field_service_enabled';
    }

    protected static ?string $model = ServiceAppointment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.support';

    protected static ?int $navigationSort = 704;

    protected static ?string $recordTitleAttribute = 'id';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.field_service');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.service_appointment');
    }

    #[\Override]
    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.service_appointments');
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return ServiceAppointmentsTable::configure($table);
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
            'index' => ListServiceAppointments::route('/'),
        ];
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'employee.user:id,name',
                'serviceRecord.maintenanceRecord.customer:id,company_name',
                'serviceRecord.maintenanceRecord.ticket:id,ticket_number',
                'serviceRecord.maintenanceRecord.installation.checks',
                'serviceRecord.maintenanceRecord.installation.shipment',
                'serviceRecord.maintenanceRecord.calibration.measurements',
                'serviceRecord.maintenanceRecord.productVariant.product',
                'serviceRecord.maintenanceRecord.serializedInventoryUnit:id,serial_number',
            ]);
    }
}
