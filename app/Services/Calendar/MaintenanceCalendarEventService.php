<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Resources\MaintenanceSchedules\MaintenanceScheduleResource;
use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceScheduleOccurrence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class MaintenanceCalendarEventService
{
    /** @return Collection<int|string, Collection<int, array{date:string,title:string,subtitle:string|null,status:'completed'|'missed'|'pending'|'raised'|'skipped',url:string}>> */
    public function between(Carbon $from, Carbon $until): Collection
    {
        return MaintenanceScheduleOccurrence::query()
            ->with(['schedule.customer:id,company_name', 'schedule.serializedInventoryUnit:id,serial_number', 'maintenanceRecord:id'])
            ->whereBetween('due_on', [$from->toDateString(), $until->toDateString()])
            ->orderBy('due_on')
            ->get()
            ->map(function (MaintenanceScheduleOccurrence $occurrence): ?array {
                $schedule = $occurrence->schedule;
                if (! $schedule instanceof MaintenanceSchedule) {
                    return null;
                }

                $customerName = data_get($schedule, 'customer.company_name');
                $serialNumber = data_get($schedule, 'serializedInventoryUnit.serial_number');

                return [
                    'date' => $occurrence->due_on->toDateString(),
                    'title' => $schedule->name,
                    'subtitle' => is_string($customerName) ? $customerName : (is_string($serialNumber) ? $serialNumber : null),
                    'status' => $occurrence->status->value,
                    'url' => $occurrence->maintenance_record_id !== null
                        ? MaintenanceRequestResource::getUrl('view', ['record' => $occurrence->maintenance_record_id])
                        : MaintenanceScheduleResource::getUrl('view', ['record' => $schedule]),
                ];
            })
            ->filter()
            ->values()
            ->groupBy('date');
    }
}
