<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Filament\Resources\Visits\VisitResource;
use App\Models\CustomerVisit;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class VisitCalendarEventService
{
    /** @return Collection<int|string, Collection<int, array{date:string,time:non-falsy-string,type:'visit',title:string,subtitle:string|null,status:string,url:string}>> */
    public function between(Carbon $from, Carbon $until): Collection
    {
        return CustomerVisit::query()
            ->with(['customer:id,company_name', 'employee.user:id,name'])
            ->whereBetween('scheduled_start_at', [$from->copy()->startOfDay(), $until->copy()->endOfDay()])
            ->get()
            ->map(function (CustomerVisit $visit): ?array {
                $scheduledAt = $visit->effectiveScheduledStart();

                if (! $scheduledAt instanceof Carbon) {
                    return null;
                }

                $customerName = data_get($visit, 'customer.company_name');
                $employeeName = data_get($visit, 'employee.user.name');

                return [
                    'date' => $scheduledAt->toDateString(),
                    'time' => $scheduledAt->format('H:i'),
                    'type' => 'visit',
                    'title' => is_string($customerName) ? $customerName : (string) __('Customer visit'),
                    'subtitle' => is_string($employeeName) ? $employeeName : null,
                    'status' => $visit->status->label(),
                    'url' => VisitResource::getUrl('view', ['record' => $visit]),
                ];
            })
            ->filter()
            ->sortBy(['date', 'time'])
            ->values()
            ->groupBy('date');
    }
}
