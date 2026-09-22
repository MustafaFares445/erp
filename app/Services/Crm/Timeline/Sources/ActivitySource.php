<?php

declare(strict_types=1);

namespace App\Services\Crm\Timeline\Sources;

use App\Data\Crm\TimelineEvent;
use App\Enums\CrmPermission;
use App\Enums\EmployeePermission;
use App\Enums\SalesPermission;
use App\Enums\SupportPermission;
use App\Models\AuditLog;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\Interaction;
use App\Models\Invoice;
use App\Models\MaintenanceRecord;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Crm\Timeline\TimelineSource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Status changes" (CR-05) — the `activity_log` rows belonging to this
 * customer's own documents, scoped one join-branch per subject type so
 * that a support-only actor never sees an invoice's status history: an
 * activity row inherits the visibility of the record it happened to, not
 * a permission of its own.
 */
final readonly class ActivitySource implements TimelineSource
{
    /**
     * @var array<class-string, array{table: string, column: string, permission: string}>
     */
    private const array SUBJECTS = [
        Quotation::class => ['table' => 'quotations', 'column' => 'customer_id', 'permission' => SalesPermission::QuotationView->value],
        Order::class => ['table' => 'orders', 'column' => 'customer_id', 'permission' => SalesPermission::OrderView->value],
        Invoice::class => ['table' => 'invoices', 'column' => 'customer_id', 'permission' => SalesPermission::InvoiceView->value],
        Payment::class => ['table' => 'payments', 'column' => 'customer_id', 'permission' => SalesPermission::PaymentView->value],
        Ticket::class => ['table' => 'tickets', 'column' => 'customer_id', 'permission' => SupportPermission::TicketView->value],
        CustomerVisit::class => ['table' => 'customer_visits', 'column' => 'customer_id', 'permission' => EmployeePermission::VisitView->value],
        MaintenanceRecord::class => ['table' => 'maintenance_records', 'column' => 'customer_id', 'permission' => SupportPermission::MaintenanceRequestView->value],
    ];

    #[\Override]
    public function key(): string
    {
        return 'activity';
    }

    #[\Override]
    public function permission(User $actor): bool
    {
        if ($actor->can(CrmPermission::InteractionView->value)) {
            return true;
        }

        return array_any(self::SUBJECTS, fn (array $subject) => $actor->can($subject['permission']));
    }

    #[\Override]
    public function subQuery(CustomerProfile $customer, User $actor, ?Carbon $from, ?Carbon $until, ?string $search): QueryBuilder
    {
        $branches = [];

        foreach (self::SUBJECTS as $subjectClass => $subject) {
            if (! $actor->can($subject['permission'])) {
                continue;
            }

            $branches[] = $this->branch($subjectClass, $subject['table'], $subject['column'], $customer, $from, $until, $search);
        }

        if ($actor->can(CrmPermission::InteractionView->value)) {
            $branches[] = $this->interactionBranch($customer, $from, $until, $search);
        }

        if ($branches === []) {
            return DB::table('activity_log')
                ->selectRaw("id, 'activity' as type, created_at as occurred_at")
                ->whereRaw('1 = 0');
        }

        $union = array_shift($branches);

        foreach ($branches as $branch) {
            $union->unionAll($branch);
        }

        return $union;
    }

    private function branch(string $subjectClass, string $table, string $column, CustomerProfile $customer, ?Carbon $from, ?Carbon $until, ?string $search): QueryBuilder
    {
        $query = DB::table('activity_log')
            ->join($table, fn (JoinClause $join) => $join->on('activity_log.subject_id', '=', "{$table}.id"))
            ->where('activity_log.subject_type', $subjectClass)
            ->where("{$table}.{$column}", $customer->id)
            ->selectRaw("activity_log.id, 'activity' as type, activity_log.created_at as occurred_at");

        return $this->applyFilters($query, $from, $until, $search);
    }

    private function interactionBranch(CustomerProfile $customer, ?Carbon $from, ?Carbon $until, ?string $search): QueryBuilder
    {
        $query = DB::table('activity_log')
            ->join('interactions', fn (JoinClause $join) => $join->on('activity_log.subject_id', '=', 'interactions.id'))
            ->where('activity_log.subject_type', Interaction::class)
            ->where('interactions.subject_type', CustomerProfile::class)
            ->where('interactions.subject_id', $customer->id)
            ->selectRaw("activity_log.id, 'activity' as type, activity_log.created_at as occurred_at");

        return $this->applyFilters($query, $from, $until, $search);
    }

    private function applyFilters(QueryBuilder $query, ?Carbon $from, ?Carbon $until, ?string $search): QueryBuilder
    {
        if ($from instanceof Carbon) {
            $query->where('activity_log.created_at', '>=', $from);
        }

        if ($until instanceof Carbon) {
            $query->where('activity_log.created_at', '<=', $until);
        }

        if (is_string($search) && $search !== '') {
            $query->where('activity_log.description', 'like', "%{$search}%");
        }

        return $query;
    }

    #[\Override]
    public function hydrate(array $ids): array
    {
        return AuditLog::query()->whereKey($ids)
            ->with('causer:id,name')
            ->get()
            ->mapWithKeys(fn (AuditLog $log): array => [$log->id => new TimelineEvent(
                type: 'activity',
                id: $log->id,
                occurredAt: $log->created_at ?? Carbon::now(),
                occurredAtIsDateOnly: false,
                title: $this->title($log),
                detail: $this->detail($log),
                statusLabel: null,
                statusColor: null,
                icon: Heroicon::OutlinedClock,
                amountMinor: null,
                currency: null,
                actorName: $log->causer instanceof User ? $log->causer->name : null,
                link: null,
                isSystem: true,
            )])
            ->all();
    }

    private function title(AuditLog $log): string
    {
        $description = (string) $log->description;
        $key = 'admin.crm.timeline.activity.'.$description;
        $translated = __($key);

        if ($translated !== $key) {
            return $translated;
        }

        // Falls back to a readable rendering of the `<module>.<entity>.<event>`
        // convention (e.g. `support.ticket.status_changed`) so an
        // untranslated new activity description degrades to legible text
        // instead of a raw dotted key.
        return str($description)->replace(['.', '_'], ' ')->title()->toString();
    }

    private function detail(AuditLog $log): ?string
    {
        $changes = $log->attribute_changes;

        if (! $changes instanceof Collection) {
            return null;
        }

        $old = $changes->get('old');
        $new = $changes->get('attributes');

        if (! is_array($old) || ! is_array($new) || ! isset($old['status'], $new['status'])) {
            return null;
        }

        $oldStatus = $old['status'];
        $newStatus = $new['status'];

        if (! is_scalar($oldStatus) || ! is_scalar($newStatus)) {
            return null;
        }

        $from = str((string) $oldStatus)->headline()->toString();
        $to = str((string) $newStatus)->headline()->toString();

        return "{$from} → {$to}";
    }
}
