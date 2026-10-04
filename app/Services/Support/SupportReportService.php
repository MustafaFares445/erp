<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\SupportPermission;
use App\Enums\TicketStatus;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceScheduleOccurrence;
use App\Models\MaintenanceTask;
use App\Models\ServiceAppointment;
use App\Models\ServiceRecordPart;
use App\Models\Ticket;
use App\Models\TicketSatisfactionResponse;
use App\Models\TicketSlaMilestone;
use App\Models\User;
use App\Models\WarrantyRecoveryClaim;
use App\Services\Employees\EmployeeReportService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Workload, SLA, and maintenance aggregates (FR-090–094), self-checking
 * like {@see EmployeeReportService}. Read-only —
 * every method computes from existing tables, backed by no report table of
 * its own (data-model.md's "Support Report" key entity has none by design).
 */
final readonly class SupportReportService
{
    public function canView(User $actor): bool
    {
        return $actor->can(SupportPermission::ReportView->value);
    }

    /** @throws DomainException */
    public function authorizeView(User $actor): void
    {
        if (! $this->canView($actor)) {
            throw new DomainException('You are not authorized to view Support reports.');
        }
    }

    /**
     * Open-ticket workload by status, priority, and assignee (FR-091).
     *
     * @return array{total_open: int, by_status: array<string, int>, by_priority: array<string, int>, by_assignee: list<array{name: string, count: int}>}
     */
    public function workload(User $actor): array
    {
        $this->authorizeView($actor);

        $openTickets = Ticket::query()
            ->whereNotIn('status', [TicketStatus::Closed, TicketStatus::Cancelled])
            ->with('assignedEmployee.user:id,name')
            ->get();

        /** @var array<string, int> $byStatus */
        $byStatus = [];
        /** @var array<string, int> $byPriority */
        $byPriority = [];
        /** @var array<int, array{name: string, count: int}> $assigneeCounts */
        $assigneeCounts = [];

        foreach ($openTickets as $ticket) {
            $byStatus[$ticket->status->value] = ($byStatus[$ticket->status->value] ?? 0) + 1;
            $byPriority[$ticket->priority->value] = ($byPriority[$ticket->priority->value] ?? 0) + 1;

            if ($ticket->assigned_employee_id === null) {
                continue;
            }

            $name = 'Unknown';
            $employee = $ticket->assignedEmployee;

            if ($employee instanceof EmployeeProfile && $employee->user instanceof User) {
                $name = $employee->user->name;
            }

            $assigneeCounts[$ticket->assigned_employee_id] ??= ['name' => $name, 'count' => 0];
            $assigneeCounts[$ticket->assigned_employee_id]['count']++;
        }

        return [
            'total_open' => $openTickets->count(),
            'by_status' => $byStatus,
            'by_priority' => $byPriority,
            'by_assignee' => array_values($assigneeCounts),
        ];
    }

    /**
     * SLA breach counts and average resolution time for tickets whose
     * clock started (`live_at`) within the chosen period (FR-092).
     *
     * @return array{response_breaches: int, resolution_breaches: int, average_resolution_minutes: float|null}
     */
    public function sla(User $actor, ?Carbon $from, ?Carbon $until): array
    {
        $this->authorizeView($actor);

        $query = Ticket::query()->whereNotNull('live_at');
        $this->applyPeriod($query, 'live_at', $from, $until);

        // Live-accurate (not just the stored flag): a ticket already past its due time counts
        // as breached here immediately, without waiting for the next scheduled sweep (FR-054).
        $responseBreaches = (clone $query)->responseBreached()->count();
        $resolutionBreaches = (clone $query)->resolutionBreached()->count();

        $resolved = (clone $query)->whereNotNull('resolved_at')->get(['live_at', 'resolved_at']);
        $resolutionMinutes = $resolved
            ->map(function (Ticket $ticket): int {
                // Both columns are guaranteed non-null here: the outer query
                // already filters whereNotNull('live_at'), and $resolved itself
                /** @var Carbon $liveAt */
                $liveAt = $ticket->live_at;
                /** @var Carbon $resolvedAt */
                $resolvedAt = $ticket->resolved_at;

                return (int) $liveAt->diffInMinutes($resolvedAt);
            })
            ->filter(fn (?int $minutes): bool => $minutes !== null);
        $averageResolutionMinutes = $resolutionMinutes->isEmpty() ? null : round($resolutionMinutes->avg() ?? 0, 1);

        return [
            'response_breaches' => $responseBreaches,
            'resolution_breaches' => $resolutionBreaches,
            'average_resolution_minutes' => $averageResolutionMinutes,
        ];
    }

    /**
     * Open maintenance requests, overdue service records, and spare parts
     * consumed within the chosen period (FR-093).
     *
     * @return array{open_requests: int, overdue_service_records: int, parts_consumed: int}
     */
    public function maintenance(User $actor, ?Carbon $from, ?Carbon $until): array
    {
        $this->authorizeView($actor);

        $openRequests = MaintenanceRecord::query()
            ->whereIn('status', [MaintenanceStatus::Open, MaintenanceStatus::InProgress])
            ->count();

        $overdueServiceRecords = MaintenanceTask::query()
            ->whereNotIn('status', [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled])
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();

        $partsQuery = ServiceRecordPart::query();
        $this->applyPeriod($partsQuery, 'created_at', $from, $until);

        return [
            'open_requests' => $openRequests,
            'overdue_service_records' => $overdueServiceRecords,
            'parts_consumed' => $partsQuery->count(),
        ];
    }

    /**
     * Per-job cost/revenue/margin for every billed or warranty-covered job in
     * the chosen period (WP-2.9, GAP-MW-09) — MT-05's headline requirement:
     * free-of-charge service made visible as a cost centre, with
     * warranty-covered work shown at zero revenue and its real cost. Derived
     * entirely from {@see MaintenanceCostService}'s snapshots; this method
     * never corrects, plugs, or hides a figure it aggregates.
     *
     * @return array{
     *     jobs: list<array{maintenance_record_id: int, customer: string|null, equipment: string|null, billing_type: string, cost_minor: int, revenue_minor: int, margin_minor: int}>,
     *     total_cost_minor: int,
     *     total_revenue_minor: int,
     *     total_margin_minor: int,
     *     warranty_cost_minor: int,
     * }
     */
    public function serviceMargin(User $actor, ?Carbon $from, ?Carbon $until): array
    {
        $this->authorizeView($actor);

        $costService = app(MaintenanceCostService::class);

        $query = MaintenanceRecord::query()
            ->whereIn('billing_type', [
                MaintenanceBillingType::WarrantyCovered->value,
                MaintenanceBillingType::TicketSettled->value,
                MaintenanceBillingType::Invoiced->value,
            ])
            ->with(['customer.user:id,name', 'serializedInventoryUnit:id,serial_number']);
        $this->applyPeriod($query, 'billed_at', $from, $until);

        $jobs = [];
        $totalCost = 0;
        $totalRevenue = 0;
        $warrantyCost = 0;

        foreach ($query->get() as $record) {
            $margin = $costService->marginFor($record);

            $jobs[] = [
                'maintenance_record_id' => (int) $record->id,
                'customer' => $record->customer?->user?->name,
                'equipment' => $record->serializedInventoryUnit?->serial_number,
                'billing_type' => $margin['billing_type'],
                'cost_minor' => $margin['cost_minor'],
                'revenue_minor' => $margin['revenue_minor'],
                'margin_minor' => $margin['margin_minor'],
            ];

            $totalCost += $margin['cost_minor'];
            $totalRevenue += $margin['revenue_minor'];

            if ($record->billing_type === MaintenanceBillingType::WarrantyCovered) {
                $warrantyCost += $margin['cost_minor'];
            }
        }

        return [
            'jobs' => $jobs,
            'total_cost_minor' => $totalCost,
            'total_revenue_minor' => $totalRevenue,
            'total_margin_minor' => $totalRevenue - $totalCost,
            'warranty_cost_minor' => $warrantyCost,
        ];
    }

    /**
     * Preventive-maintenance compliance for occurrences due within the
     * chosen period (WP-3.6, GAP-MW-08, MT-07) — due/raised/completed/missed
     * counts by customer and equipment, following {@see self::serviceMargin()}'s
     * pattern of a flat aggregate derived purely from existing rows.
     *
     * @return array{
     *     total_due: int,
     *     raised: int,
     *     completed: int,
     *     missed: int,
     *     skipped: int,
     *     by_customer: list<array{customer: string|null, due: int, completed: int, missed: int}>,
     *     by_equipment: list<array{equipment: string|null, due: int, completed: int, missed: int}>,
     * }
     */
    public function preventiveCompliance(User $actor, ?Carbon $from, ?Carbon $until): array
    {
        $this->authorizeView($actor);

        $query = MaintenanceScheduleOccurrence::query()
            ->with(['schedule.customer.user:id,name', 'schedule.serializedInventoryUnit:id,serial_number']);
        $this->applyPeriod($query, 'due_on', $from, $until);

        $occurrences = $query->get();

        $byCustomer = [];
        $byEquipment = [];
        $counts = ['raised' => 0, 'completed' => 0, 'missed' => 0, 'skipped' => 0];

        foreach ($occurrences as $occurrence) {
            $status = $occurrence->status;

            if (isset($counts[$status->value])) {
                $counts[$status->value]++;
            }

            $schedule = $occurrence->schedule;
            $customerName = $schedule instanceof MaintenanceSchedule ? $schedule->customer?->user?->name : null;
            $equipmentSerial = $schedule instanceof MaintenanceSchedule ? $schedule->serializedInventoryUnit?->serial_number : null;

            $customerKey = $customerName ?? '—';
            $byCustomer[$customerKey] ??= ['customer' => $customerName, 'due' => 0, 'completed' => 0, 'missed' => 0];
            $byCustomer[$customerKey]['due']++;
            $byCustomer[$customerKey]['completed'] += $status === OccurrenceStatus::Completed ? 1 : 0;
            $byCustomer[$customerKey]['missed'] += $status === OccurrenceStatus::Missed ? 1 : 0;

            $equipmentKey = $equipmentSerial ?? '—';
            $byEquipment[$equipmentKey] ??= ['equipment' => $equipmentSerial, 'due' => 0, 'completed' => 0, 'missed' => 0];
            $byEquipment[$equipmentKey]['due']++;
            $byEquipment[$equipmentKey]['completed'] += $status === OccurrenceStatus::Completed ? 1 : 0;
            $byEquipment[$equipmentKey]['missed'] += $status === OccurrenceStatus::Missed ? 1 : 0;
        }

        return [
            'total_due' => $occurrences->count(),
            'raised' => $counts['raised'],
            'completed' => $counts['completed'],
            'missed' => $counts['missed'],
            'skipped' => $counts['skipped'],
            'by_customer' => array_values($byCustomer),
            'by_equipment' => array_values($byEquipment),
        ];
    }

    /**
     * Age distribution of every non-terminal ticket. This is intentionally a
     * current-backlog metric rather than a period metric.
     *
     * @return array{total:int,under_24h:int,one_to_three_days:int,four_to_seven_days:int,over_seven_days:int}
     */
    public function backlogAging(User $actor): array
    {
        $this->authorizeView($actor);

        $openTickets = Ticket::query()
            ->whereNotIn('status', [
                TicketStatus::Resolved->value,
                TicketStatus::Closed->value,
                TicketStatus::Cancelled->value,
            ])
            ->get(['created_at']);

        $now = now();
        $buckets = [
            'under_24h' => 0,
            'one_to_three_days' => 0,
            'four_to_seven_days' => 0,
            'over_seven_days' => 0,
        ];

        foreach ($openTickets as $ticket) {
            $createdAt = $ticket->created_at;

            if ($createdAt === null) {
                continue;
            }

            $hours = $createdAt->diffInHours($now);

            if ($hours < 24) {
                $buckets['under_24h']++;
            } elseif ($hours < 96) {
                $buckets['one_to_three_days']++;
            } elseif ($hours < 192) {
                $buckets['four_to_seven_days']++;
            } else {
                $buckets['over_seven_days']++;
            }
        }

        return ['total' => $openTickets->count(), ...$buckets];
    }

    /**
     * Milestone-level SLA compliance. A completed milestone is compliant only
     * when it has never recorded a breach.
     *
     * @return array{completed:int,compliant:int,breached:int,compliance_percent:float|null}
     */
    public function slaCompliance(User $actor, ?Carbon $from, ?Carbon $until): array
    {
        $this->authorizeView($actor);

        $query = TicketSlaMilestone::query()->whereNotNull('completed_at');
        $this->applyPeriod($query, 'completed_at', $from, $until);

        $completed = (clone $query)->count();
        $breached = (clone $query)->whereNotNull('breached_at')->count();
        $compliant = max(0, $completed - $breached);

        return [
            'completed' => $completed,
            'compliant' => $compliant,
            'breached' => $breached,
            'compliance_percent' => $completed > 0 ? round(($compliant / $completed) * 100, 1) : null,
        ];
    }

    /**
     * @return array{count:int,average_minutes:float|null}
     */
    public function responseTime(User $actor, ?Carbon $from, ?Carbon $until): array
    {
        $this->authorizeView($actor);

        $query = Ticket::query()
            ->whereNotNull('first_response_at')
            ->whereNotNull('response_sla_started_at');
        $this->applyPeriod($query, 'first_response_at', $from, $until);

        $minutes = [];

        foreach ($query->get(['response_sla_started_at', 'first_response_at']) as $ticket) {
            $startedAt = $ticket->response_sla_started_at;
            $respondedAt = $ticket->first_response_at;

            if ($startedAt !== null && $respondedAt !== null) {
                $minutes[] = (float) $startedAt->diffInMinutes($respondedAt);
            }
        }

        return [
            'count' => count($minutes),
            'average_minutes' => $minutes === [] ? null : round(array_sum($minutes) / count($minutes), 1),
        ];
    }

    /**
     * @return array{count:int,average_minutes:float|null}
     */
    public function resolutionTime(User $actor, ?Carbon $from, ?Carbon $until): array
    {
        $this->authorizeView($actor);

        $query = Ticket::query()
            ->whereNotNull('resolved_at')
            ->whereNotNull('live_at');
        $this->applyPeriod($query, 'resolved_at', $from, $until);

        $minutes = [];

        foreach ($query->get(['live_at', 'resolved_at']) as $ticket) {
            $liveAt = $ticket->live_at;
            $resolvedAt = $ticket->resolved_at;

            if ($liveAt !== null && $resolvedAt !== null) {
                $minutes[] = (float) $liveAt->diffInMinutes($resolvedAt);
            }
        }

        return [
            'count' => count($minutes),
            'average_minutes' => $minutes === [] ? null : round(array_sum($minutes) / count($minutes), 1),
        ];
    }

    /**
     * @return array{eligible:int,reopened:int,reopen_rate_percent:float|null}
     */
    public function reopenRate(User $actor, ?Carbon $from, ?Carbon $until): array
    {
        $this->authorizeView($actor);

        $query = Ticket::query()->whereNotNull('resolved_at');
        $this->applyPeriod($query, 'resolved_at', $from, $until);

        $eligible = (clone $query)->count();
        $reopened = (clone $query)->where('reopened_count', '>', 0)->count();

        return [
            'eligible' => $eligible,
            'reopened' => $reopened,
            'reopen_rate_percent' => $eligible > 0 ? round(($reopened / $eligible) * 100, 1) : null,
        ];
    }

    /**
     * @return array{responses:int,average_rating:float|null,distribution:array<int,int>}
     */
    public function customerSatisfaction(User $actor, ?Carbon $from, ?Carbon $until): array
    {
        $this->authorizeView($actor);

        $query = TicketSatisfactionResponse::query();
        $this->applyPeriod($query, 'submitted_at', $from, $until);

        $responses = $query->get(['rating']);
        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];

        foreach ($responses as $response) {
            $rating = (int) $response->rating;

            if (isset($distribution[$rating])) {
                $distribution[$rating]++;
            }
        }

        return [
            'responses' => $responses->count(),
            'average_rating' => $responses->isEmpty() ? null : round((float) $responses->avg('rating'), 2),
            'distribution' => $distribution,
        ];
    }

    /**
     * @return list<array{employee_id:int,name:string,count:int}>
     */
    public function assignmentLoad(User $actor): array
    {
        $this->authorizeView($actor);

        $tickets = Ticket::query()
            ->whereNotNull('assigned_employee_id')
            ->whereNotIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value, TicketStatus::Cancelled->value])
            ->with('assignedEmployee.user:id,name')
            ->get();

        $load = [];

        foreach ($tickets as $ticket) {
            $employeeId = (int) $ticket->assigned_employee_id;

            $load[$employeeId] ??= [
                'employee_id' => $employeeId,
                'name' => $ticket->assignedEmployee->user->name ?? 'Unknown',
                'count' => 0,
            ];
            $load[$employeeId]['count']++;
        }

        $rows = array_values($load);
        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $rows;
    }

    /**
     * Scheduled and actual on-site time by technician. No utilization
     * percentage is produced until an authoritative working-capacity
     * calendar exists.
     *
     * @return list<array{employee_id:int,name:string,appointment_count:int,scheduled_minutes:int,actual_on_site_minutes:int}>
     */
    public function technicianUtilization(User $actor, ?Carbon $from, ?Carbon $until): array
    {
        $this->authorizeView($actor);

        $query = ServiceAppointment::query()
            ->whereNotNull('employee_id')
            ->with('employee.user:id,name');
        $this->applyPeriod($query, 'scheduled_start_at', $from, $until);

        $utilization = [];

        foreach ($query->get() as $appointment) {
            $employeeId = $appointment->employee_id;

            $utilization[$employeeId] ??= [
                'employee_id' => $employeeId,
                'name' => $appointment->employee->user->name ?? 'Unknown',
                'appointment_count' => 0,
                'scheduled_minutes' => 0,
                'actual_on_site_minutes' => 0,
            ];
            $utilization[$employeeId]['appointment_count']++;
            $utilization[$employeeId]['scheduled_minutes'] += max(0, (int) $appointment->scheduled_start_at->diffInMinutes($appointment->scheduled_end_at));

            if ($appointment->checked_in_at !== null && $appointment->checked_out_at !== null) {
                $utilization[$employeeId]['actual_on_site_minutes'] += max(0, (int) $appointment->checked_in_at->diffInMinutes($appointment->checked_out_at));
            }
        }

        return array_values($utilization);
    }

    /**
     * @return list<array{serialized_inventory_unit_id:int,failure_category:string,count:int}>
     */
    public function repeatFailures(User $actor, ?Carbon $from, ?Carbon $until): array
    {
        $this->authorizeView($actor);

        $query = MaintenanceRecord::query()
            ->whereNotNull('serialized_inventory_unit_id')
            ->whereNotNull('failure_category');
        $this->applyPeriod($query, 'created_at', $from, $until);

        $failures = [];

        foreach ($query->get(['serialized_inventory_unit_id', 'failure_category']) as $record) {
            // The query above only returns records with both columns set.
            $unitId = (int) $record->serialized_inventory_unit_id;
            $category = (string) $record->failure_category?->value;

            $key = $unitId.'|'.$category;
            $failures[$key] ??= [
                'serialized_inventory_unit_id' => $unitId,
                'failure_category' => $category,
                'count' => 0,
            ];
            $failures[$key]['count']++;
        }

        return array_values(array_filter(
            $failures,
            static fn (array $failure): bool => $failure['count'] > 1,
        ));
    }

    /**
     * @return array{claims:int,claimed_minor:int,approved_minor:int,received_minor:int,outstanding_minor:int,recovery_percent:float|null}
     */
    public function warrantyRecoveryPerformance(User $actor, ?Carbon $from, ?Carbon $until): array
    {
        $this->authorizeView($actor);

        $query = WarrantyRecoveryClaim::query();
        $this->applyPeriod($query, 'created_at', $from, $until);

        $claims = $query->get();
        $claimed = 0;
        $approved = 0;
        $received = 0;
        $outstanding = 0;

        foreach ($claims as $claim) {
            $claimed += $claim->claimed_amount_minor;
            $approved += $claim->approved_amount_minor ?? 0;
            $received += $claim->received_amount_minor;
            $outstanding += $claim->outstandingMinor();
        }

        return [
            'claims' => $claims->count(),
            'claimed_minor' => $claimed,
            'approved_minor' => $approved,
            'received_minor' => $received,
            'outstanding_minor' => $outstanding,
            'recovery_percent' => $claimed > 0 ? round(($received / $claimed) * 100, 1) : null,
        ];
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyPeriod(Builder $query, string $column, ?Carbon $from, ?Carbon $until): void
    {
        if ($from instanceof Carbon) {
            $query->where($column, '>=', $from);
        }

        if ($until instanceof Carbon) {
            $query->where($column, '<=', $until);
        }
    }
}
