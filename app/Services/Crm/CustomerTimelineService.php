<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Data\Crm\TimelineEvent;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\QuotationStatus;
use App\Enums\TicketStatus;
use App\Models\CustomerProfile;
use App\Models\Interaction;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Crm\Timeline\Sources\ActivitySource;
use App\Services\Crm\Timeline\Sources\InteractionSource;
use App\Services\Crm\Timeline\Sources\InvoiceSource;
use App\Services\Crm\Timeline\Sources\MaintenanceRecordSource;
use App\Services\Crm\Timeline\Sources\OrderSource;
use App\Services\Crm\Timeline\Sources\PaymentSource;
use App\Services\Crm\Timeline\Sources\QuotationSource;
use App\Services\Crm\Timeline\Sources\TicketSource;
use App\Services\Crm\Timeline\Sources\VisitSource;
use App\Services\Crm\Timeline\TimelineSource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * A customer's whole relationship in one reverse-chronological stream
 * (WP-3.1, GAP-UI-03, CR-05) — a **paginated union** of nine sources, not
 * nine eager loads rendered together, so a five-year customer does not
 * load thousands of rows to show twenty.
 *
 * Each source is filtered by the acting user's own module permission
 * (XC-05's "hiding a control is not authorisation") before it ever
 * reaches the union query, not after rendering. Presentation for each
 * source — title, status label/color, money, actor, related links — lives
 * on the {@see TimelineSource} itself, not here.
 */
final readonly class CustomerTimelineService
{
    public const array TYPES = [
        'quotation', 'order', 'invoice', 'payment', 'ticket', 'interaction', 'visit', 'maintenance_record', 'activity',
    ];

    /** @var array<string, TimelineSource> */
    private array $sources;

    public function __construct(private AccountsReceivableService $receivables)
    {
        $this->sources = collect([
            new QuotationSource,
            new OrderSource,
            new InvoiceSource,
            new PaymentSource,
            new TicketSource,
            new InteractionSource,
            new VisitSource,
            new MaintenanceRecordSource,
            new ActivitySource,
        ])->keyBy(fn (TimelineSource $source): string => $source->key())->all();
    }

    /**
     * @param  list<string>  $types
     * @return LengthAwarePaginator<int, TimelineEvent>
     */
    public function timeline(
        CustomerProfile $customer,
        User $actor,
        ?Carbon $from,
        ?Carbon $until,
        array $types = [],
        int $perPage = 20,
        ?string $search = null,
        bool $showActivity = true,
    ): LengthAwarePaginator {
        $requested = $types === [] ? self::TYPES : array_values(array_intersect(self::TYPES, $types));

        if (! $showActivity) {
            $requested = array_values(array_diff($requested, ['activity']));
        }

        $permitted = array_values(array_filter(
            $requested,
            fn (string $type): bool => $this->sources[$type]->permission($actor),
        ));

        if ($permitted === []) {
            return new LengthAwarePaginator([], 0, $perPage);
        }

        $subQueries = array_map(
            fn (string $type): QueryBuilder => $this->sources[$type]->subQuery($customer, $actor, $from, $until, $search),
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
            foreach ($this->sources[$type]->hydrate($ids) as $id => $event) {
                $hydrated["{$type}:{$id}"] = $event;
            }
        }

        $events = array_map(
            fn (array $row): TimelineEvent => $hydrated["{$row['type']}:{$row['id']}"] ?? $this->fallbackEvent($row),
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
     *     overdue_documents_count: int,
     *     open_quotations_value_minor: int,
     *     open_orders_value_minor: int,
     *     open_tickets: int,
     *     last_interaction_at: ?Carbon,
     * }
     */
    public function summary(CustomerProfile $customer): array
    {
        $lifetimeInvoicedMinor = JournalEntryLine::toMinorUnits(
            Invoice::query()
                ->where('customer_id', $customer->id)
                ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Cancelled->value])
                ->sum('total_amount'),
        );

        $lifetimeCollectedMinor = JournalEntryLine::toMinorUnits(
            Payment::query()
                ->where('customer_id', $customer->id)
                ->where('status', 'posted')
                ->sum('amount'),
        );

        $terminalQuotationStatuses = collect(QuotationStatus::cases())
            ->filter(fn (QuotationStatus $status): bool => $status->isTerminal())
            ->map(fn (QuotationStatus $status): string => $status->value)
            ->all();

        $openQuotationsValueMinor = JournalEntryLine::toMinorUnits(
            Quotation::query()
                ->where('customer_id', $customer->id)
                ->whereNotIn('status', $terminalQuotationStatuses)
                ->sum('grand_total'),
        );

        $terminalOrderStatuses = collect(OrderStatus::cases())
            ->filter(fn (OrderStatus $status): bool => $status->isTerminal())
            ->map(fn (OrderStatus $status): string => $status->value)
            ->all();

        $openOrdersValueMinor = JournalEntryLine::toMinorUnits(
            Order::query()
                ->where('customer_id', $customer->id)
                ->whereNotIn('status', $terminalOrderStatuses)
                ->sum('grand_total'),
        );

        $openTickets = Ticket::query()
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value, TicketStatus::Cancelled->value])
            ->count();

        $lastInteractionAt = Interaction::query()
            ->where('subject_type', CustomerProfile::class)
            ->where('subject_id', $customer->id)
            ->max('occurred_at');

        $outstanding = $this->receivables->customerDetail($customer);

        return [
            'lifetime_invoiced_minor' => $lifetimeInvoicedMinor,
            'lifetime_collected_minor' => $lifetimeCollectedMinor,
            'outstanding' => $outstanding,
            'overdue_documents_count' => count($outstanding['documents']),
            'open_quotations_value_minor' => $openQuotationsValueMinor,
            'open_orders_value_minor' => $openOrdersValueMinor,
            'open_tickets' => $openTickets,
            'last_interaction_at' => is_string($lastInteractionAt) ? Carbon::parse($lastInteractionAt) : null,
        ];
    }

    /**
     * @param  array{id: int, type: string, occurred_at: mixed}  $row
     */
    private function fallbackEvent(array $row): TimelineEvent
    {
        $occurredAtRaw = $row['occurred_at'];
        $occurredAt = match (true) {
            $occurredAtRaw instanceof Carbon => $occurredAtRaw,
            is_string($occurredAtRaw) => Carbon::parse($occurredAtRaw),
            default => Carbon::now(),
        };

        return new TimelineEvent(
            type: $row['type'],
            id: $row['id'],
            occurredAt: $occurredAt,
            occurredAtIsDateOnly: false,
            title: str($row['type'])->headline()->toString(),
            detail: null,
            statusLabel: null,
            statusColor: null,
            icon: Heroicon::OutlinedClock,
            amountMinor: null,
            currency: null,
            actorName: null,
            link: null,
        );
    }
}
