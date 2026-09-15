<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceSchedules\Pages;

use App\Data\Support\MaintenanceScheduleData;
use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceIntervalType;
use App\Filament\Resources\MaintenanceSchedules\MaintenanceScheduleResource;
use App\Models\MaintenanceSchedule;
use App\Models\User;
use App\Services\Support\MaintenanceScheduleService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditMaintenanceSchedule extends EditRecord
{
    protected static string $resource = MaintenanceScheduleResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /** @param array<string, mixed> $data */
    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        if (! $record instanceof MaintenanceSchedule) {
            abort(404);
        }

        return app(MaintenanceScheduleService::class)->update($record, new MaintenanceScheduleData(
            serializedInventoryUnitId: (int) $record->serialized_inventory_unit_id,
            customerId: $record->customer_id === null ? null : (int) $record->customer_id,
            name: (string) $data['name'],
            intervalType: MaintenanceIntervalType::from((string) $data['interval_type']),
            intervalValue: (int) $data['interval_value'],
            leadTimeDays: (int) $data['lead_time_days'],
            firstDueOn: $record->first_due_on->toDateString(),
            billingType: MaintenanceBillingType::from((string) $data['billing_type']),
        ), $actor);
    }
}
