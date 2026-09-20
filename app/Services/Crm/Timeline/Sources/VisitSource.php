<?php

declare(strict_types=1);

namespace App\Services\Crm\Timeline\Sources;

use App\Data\Crm\TimelineEvent;
use App\Enums\EmployeePermission;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Services\Crm\Timeline\TimelineSource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

final readonly class VisitSource implements TimelineSource
{
    #[\Override]
    public function key(): string
    {
        return 'visit';
    }

    #[\Override]
    public function permission(User $actor): bool
    {
        return $actor->can(EmployeePermission::VisitView->value);
    }

    #[\Override]
    public function subQuery(CustomerProfile $customer, User $actor, ?Carbon $from, ?Carbon $until, ?string $search): QueryBuilder
    {
        $query = CustomerVisit::query()->toBase()
            ->selectRaw("id, 'visit' as type, planned_at as occurred_at")
            ->where('customer_id', $customer->id);

        if (is_string($search) && $search !== '') {
            $query->whereRaw('1 = 0');
        }

        if ($from instanceof Carbon) {
            $query->where('planned_at', '>=', $from);
        }

        if ($until instanceof Carbon) {
            $query->where('planned_at', '<=', $until);
        }

        return $query;
    }

    #[\Override]
    public function hydrate(array $ids): array
    {
        return CustomerVisit::query()->whereKey($ids)
            ->with('employee.user:id,name')
            ->get()
            ->mapWithKeys(function (CustomerVisit $visit): array {
                $employeeName = $visit->employee instanceof EmployeeProfile ? $visit->employee->user?->name : null;

                return [$visit->id => new TimelineEvent(
                    type: 'visit',
                    id: $visit->id,
                    occurredAt: $visit->planned_at ?? $visit->created_at ?? Carbon::now(),
                    occurredAtIsDateOnly: false,
                    title: 'Visit — '.($employeeName ?? 'Unassigned'),
                    detail: $this->detail($visit),
                    statusLabel: $visit->status->label(),
                    statusColor: $visit->status->color(),
                    icon: Heroicon::OutlinedMapPin,
                    amountMinor: null,
                    currency: null,
                    actorName: $employeeName,
                    link: route('filament.admin.resources.visits.view', ['record' => $visit->id]),
                )];
            })
            ->all();
    }

    private function detail(CustomerVisit $visit): ?string
    {
        $parts = [];

        if ($visit->checked_in_at instanceof Carbon) {
            $parts[] = "Checked in {$visit->checked_in_at->toDateTimeString()}";
        }

        if ($visit->checked_out_at instanceof Carbon) {
            $parts[] = "Checked out {$visit->checked_out_at->toDateTimeString()}";
        }

        if (is_string($visit->outcome) && $visit->outcome !== '') {
            $parts[] = $visit->outcome;
        }

        return $parts === [] ? null : implode(' — ', $parts);
    }
}
