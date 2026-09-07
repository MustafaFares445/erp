<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Enums\CrmPermission;
use App\Enums\EmployeePermission;
use App\Enums\SalesPermission;
use App\Enums\SupportPermission;
use App\Enums\TicketStatus;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\Interaction;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\MaintenanceRecord;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Accounting\AccountsReceivableService;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A customer's whole relationship in one reverse-chronological stream
 * (WP-3.1, GAP-UI-03, CR-05) — a **paginated union** of eight document
 * sources, not eight eager loads rendered together, so a five-year
 * customer does not load thousands of rows to show twenty.
 *
 * Each source is filtered by the acting user's own module permission
 * (XC-05's "hiding a control is not authorisation") before it ever
 * reaches the union query, not after rendering.
 */
final readonly class CustomerTimelineService
{
    public const array TYPES = [
        'quotation', 'order', 'invoice', 'payment', 'ticket', 'interaction', 'visit', 'maintenance_record',
    ];

    public function __construct(private AccountsReceivableService $receivables) {}

    /**
     * @param  list<string>  $types
     * @return LengthAwarePaginator<int, array{occurred_at: mixed, type: string, title: string, subtitle: ?string, link: ?string, actor: ?string}>
     */
    public function timeline(
        CustomerProfile $customer,
        User $actor,
        ?Carbon $from,
        ?Carbon $until,
        array $types = [],
        int $perPage = 20,
    ): LengthAwarePaginator {
        $requested = $types === [] ? self::TYPES : array_values(array_intersect(self::TYPES, $types));
        $permitted = array_values(array_filter($requested, fn (string $type): bool => $this->actorCanView($actor, $type)));

        if ($permitted === []) {
            return new LengthAwarePaginator([], 0, $perPage);
        }

        $subQueries = array_map(
            fn (string $type): QueryBuilder => $this->subQuery($type, $customer, $from, $until),
            $permitted,
        );

        $union = array_shift($subQueries);

        foreach ($subQueries as $subQuery) {
            $union->unionAll($subQuery);
        }

        $paginated = $union->orderByDesc('occurred_at')->paginate($perPage);

        /** @var list<array{id: int, type: string, occurred_at: mixed}> $rows */
        $rows = collect($paginated->items())
            ->map(static function (mixed $row): array {
                /** @var array{id: int, type: string, occurred_at: mixed} $row */
                $row = (array) $row;

                return $row;
            })
            ->all();

        $idsByType = [];

        foreach ($rows as $row) {
            $idsByType[$row['type']][] = $row['id'];
        }

        $hydrated = [];

        foreach ($idsByType as $type => $ids) {
            $hydrated += $this->hydrate($type, $ids);
        }

        $events = array_map(
            static fn (array $row): array => $hydrated["{$row['type']}:{$row['id']}"] ?? [
                'occurred_at' => $row['occurred_at'],
                'type' => $row['type'],
                'title' => $row['type'],
                'subtitle' => null,
                'link' => null,
                'actor' => null,
            ],
            $rows,
        );

        return new LengthAwarePaginator(
            $events,
            $paginated->total(),
            $perPage,
            $paginated->currentPage(),
            ['path' => LengthAwarePaginator::resolveCurrentPath()],
        );
    }

    /**
     * @return array{
     *     lifetime_invoiced_minor: int,
     *     lifetime_collected_minor: int,
     *     outstanding: array<string, mixed>,
     *     open_tickets: int,
     *     last_interaction_at: ?Carbon,
     * }
     */
    public function summary(CustomerProfile $customer): array
    {
        $lifetimeInvoicedMinor = (int) Invoice::query()
            ->where('customer_id', $customer->id)
            ->get(['total_amount'])
            ->sum(fn (Invoice $invoice): int => JournalEntryLine::toMinorUnits($invoice->total_amount));

        $lifetimeCollectedMinor = (int) Payment::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'posted')
            ->get(['amount'])
            ->sum(fn (Payment $payment): int => JournalEntryLine::toMinorUnits($payment->amount));

        $openTickets = Ticket::query()
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value, TicketStatus::Cancelled->value])
            ->count();

        $lastInteractionAt = Interaction::query()
            ->where('subject_type', CustomerProfile::class)
            ->where('subject_id', $customer->id)
            ->max('occurred_at');

        return [
            'lifetime_invoiced_minor' => $lifetimeInvoicedMinor,
            'lifetime_collected_minor' => $lifetimeCollectedMinor,
            // XC-04's no-disagreeing-rules principle: the outstanding figure is never
            // recomputed here, only read from the one place that already owns it.
            'outstanding' => $this->receivables->customerDetail($customer),
            'open_tickets' => $openTickets,
            'last_interaction_at' => is_string($lastInteractionAt) ? Carbon::parse($lastInteractionAt) : null,
        ];
    }

    private function actorCanView(User $actor, string $type): bool
    {
        return match ($type) {
            'quotation' => $actor->can(SalesPermission::QuotationView->value),
            'order' => $actor->can(SalesPermission::OrderView->value),
            'invoice' => $actor->can(SalesPermission::InvoiceView->value),
            'payment' => $actor->can(SalesPermission::PaymentView->value),
            'ticket' => $actor->can(SupportPermission::TicketView->value),
            'interaction' => $actor->can(CrmPermission::InteractionView->value),
            'visit' => $actor->can(EmployeePermission::VisitView->value),
            'maintenance_record' => $actor->can(SupportPermission::MaintenanceRequestView->value),
            default => false,
        };
    }

    private function subQuery(string $type, CustomerProfile $customer, ?Carbon $from, ?Carbon $until): QueryBuilder
    {
        $query = match ($type) {
            'quotation' => Quotation::query()->toBase()->selectRaw("id, 'quotation' as type, issue_date as occurred_at")->where('customer_id', $customer->id),
            'order' => Order::query()->toBase()->selectRaw("id, 'order' as type, created_at as occurred_at")->where('customer_id', $customer->id),
            'invoice' => Invoice::query()->toBase()->selectRaw("id, 'invoice' as type, invoice_date as occurred_at")->where('customer_id', $customer->id),
            'payment' => Payment::query()->toBase()->selectRaw("id, 'payment' as type, payment_date as occurred_at")->where('customer_id', $customer->id),
            'ticket' => Ticket::query()->toBase()->selectRaw("id, 'ticket' as type, created_at as occurred_at")->where('customer_id', $customer->id),
            'interaction' => Interaction::query()->toBase()->selectRaw("id, 'interaction' as type, occurred_at as occurred_at")
                ->where('subject_type', CustomerProfile::class)
                ->where('subject_id', $customer->id),
            'visit' => CustomerVisit::query()->toBase()->selectRaw("id, 'visit' as type, planned_at as occurred_at")->where('customer_id', $customer->id),
            'maintenance_record' => MaintenanceRecord::query()->toBase()->selectRaw("id, 'maintenance_record' as type, created_at as occurred_at")->where('customer_id', $customer->id),
            default => throw new InvalidArgumentException("Unknown timeline type [{$type}]."),
        };

        if ($from instanceof Carbon) {
            $query->where('occurred_at', '>=', $from);
        }

        if ($until instanceof Carbon) {
            $query->where('occurred_at', '<=', $until);
        }

        return $query;
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, array{occurred_at: mixed, type: string, title: string, subtitle: ?string, link: ?string, actor: ?string}>
     */
    private function hydrate(string $type, array $ids): array
    {
        return match ($type) {
            'quotation' => Quotation::query()->whereKey($ids)->get()->mapWithKeys(fn (Quotation $r): array => ["quotation:{$r->id}" => [
                'occurred_at' => $r->issue_date, 'type' => 'quotation',
                'title' => "Quotation {$r->quotation_number}", 'subtitle' => $r->status->value,
                'link' => route('filament.admin.resources.quotations.view', ['record' => $r->id]), 'actor' => null,
            ]])->all(),
            'order' => Order::query()->whereKey($ids)->get()->mapWithKeys(fn (Order $r): array => ["order:{$r->id}" => [
                'occurred_at' => $r->created_at, 'type' => 'order',
                'title' => "Order {$r->order_number}", 'subtitle' => $r->status,
                'link' => route('filament.admin.resources.orders.view', ['record' => $r->id]), 'actor' => null,
            ]])->all(),
            'invoice' => Invoice::query()->whereKey($ids)->get()->mapWithKeys(fn (Invoice $r): array => ["invoice:{$r->id}" => [
                'occurred_at' => $r->invoice_date, 'type' => 'invoice',
                'title' => "Invoice {$r->invoice_number}", 'subtitle' => $r->status->value,
                'link' => route('filament.admin.resources.invoices.view', ['record' => $r->id]), 'actor' => null,
            ]])->all(),
            'payment' => Payment::query()->whereKey($ids)->get()->mapWithKeys(fn (Payment $r): array => ["payment:{$r->id}" => [
                'occurred_at' => $r->payment_date, 'type' => 'payment',
                'title' => "Payment {$r->payment_number}", 'subtitle' => $r->status->value,
                'link' => route('filament.admin.resources.payments.view', ['record' => $r->id]), 'actor' => null,
            ]])->all(),
            'ticket' => Ticket::query()->whereKey($ids)->get()->mapWithKeys(fn (Ticket $r): array => ["ticket:{$r->id}" => [
                'occurred_at' => $r->created_at, 'type' => 'ticket',
                'title' => "Ticket {$r->ticket_number}", 'subtitle' => $r->status->value,
                'link' => route('filament.admin.resources.tickets.view', ['record' => $r->id]), 'actor' => null,
            ]])->all(),
            'interaction' => Interaction::query()->whereKey($ids)->with('employee:id,name')->get()->mapWithKeys(fn (Interaction $r): array => ["interaction:{$r->id}" => [
                'occurred_at' => $r->occurred_at, 'type' => 'interaction',
                'title' => str($r->type->value)->replace('_', ' ')->headline()->toString().' interaction', 'subtitle' => $r->summary,
                'link' => null, 'actor' => $r->employee?->name,
            ]])->all(),
            'visit' => CustomerVisit::query()->whereKey($ids)->get()->mapWithKeys(fn (CustomerVisit $r): array => ["visit:{$r->id}" => [
                'occurred_at' => $r->planned_at, 'type' => 'visit',
                'title' => 'Customer visit', 'subtitle' => $r->status->value,
                'link' => route('filament.admin.resources.visits.view', ['record' => $r->id]), 'actor' => null,
            ]])->all(),
            'maintenance_record' => MaintenanceRecord::query()->whereKey($ids)->get()->mapWithKeys(fn (MaintenanceRecord $r): array => ["maintenance_record:{$r->id}" => [
                'occurred_at' => $r->created_at, 'type' => 'maintenance_record',
                'title' => "Maintenance job #{$r->id}", 'subtitle' => $r->status->value,
                'link' => route('filament.admin.resources.maintenance-requests.view', ['record' => $r->id]), 'actor' => null,
            ]])->all(),
            default => [],
        };
    }
}
