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
use App\Models\ServiceRecordPart;
use App\Models\Ticket;
use App\Models\User;
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
            ->map(function (Ticket $ticket): ?int {
                // @codeCoverageIgnoreStart
                // Both columns are guaranteed non-null here: the outer query
                // already filters whereNotNull('live_at'), and $resolved itself
                // is whereNotNull('resolved_at') — required only to satisfy
                // Carbon::diffInMinutes()'s non-nullable parameter type.
                if (! $ticket->live_at instanceof Carbon || ! $ticket->resolved_at instanceof Carbon) {
                    return null;
                }

                // @codeCoverageIgnoreEnd

                return (int) $ticket->live_at->diffInMinutes($ticket->resolved_at);
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
