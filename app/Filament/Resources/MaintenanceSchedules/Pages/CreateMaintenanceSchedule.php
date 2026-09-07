<?php

declare(strict_types=1);

namespace App\Filament\Resources\MaintenanceSchedules\Pages;

use App\Data\Support\MaintenanceScheduleData;
use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceIntervalType;
use App\Filament\Resources\MaintenanceSchedules\MaintenanceScheduleResource;
use App\Models\User;
use App\Services\Support\MaintenanceScheduleService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateMaintenanceSchedule extends CreateRecord
{
    protected static string $resource = MaintenanceScheduleResource::class;

    /**
     * @param  array{serialized_inventory_unit_id: int, customer_id: int, name: string, interval_type: string, interval_value: int, lead_time_days: int, first_due_on: string, billing_type: string}  $data
     */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();

        // @codeCoverageIgnoreStart
        if (! $actor instanceof User) {
            abort(403);
        }

        // @codeCoverageIgnoreEnd

        return app(MaintenanceScheduleService::class)->create(new MaintenanceScheduleData(
            serializedInventoryUnitId: $data['serialized_inventory_unit_id'],
            customerId: $data['customer_id'],
            name: $data['name'],
            intervalType: MaintenanceIntervalType::from($data['interval_type']),
            intervalValue: $data['interval_value'],
            leadTimeDays: $data['lead_time_days'],
            firstDueOn: $data['first_due_on'],
            billingType: MaintenanceBillingType::from($data['billing_type']),
        ), $actor);
    }
}
