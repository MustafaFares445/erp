<?php

declare(strict_types=1);

namespace App\Services\Crm\Timeline\Sources;

use App\Data\Crm\TimelineEvent;
use App\Enums\SupportPermission;
use App\Models\CustomerProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Crm\Timeline\TimelineSource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

final readonly class TicketSource implements TimelineSource
{
    #[\Override]
    public function key(): string
    {
        return 'ticket';
    }

    #[\Override]
    public function permission(User $actor): bool
    {
        return $actor->can(SupportPermission::TicketView->value);
    }

    #[\Override]
    public function subQuery(CustomerProfile $customer, User $actor, ?Carbon $from, ?Carbon $until, ?string $search): QueryBuilder
    {
        $query = Ticket::query()->toBase()
            ->selectRaw("id, 'ticket' as type, created_at as occurred_at")
            ->where('customer_id', $customer->id);

        if (is_string($search) && $search !== '') {
            $query->where(function (QueryBuilder $query) use ($search): void {
                $query->where('ticket_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%");
            });
        }

        if ($from instanceof Carbon) {
            $query->where('created_at', '>=', $from);
        }

        if ($until instanceof Carbon) {
            $query->where('created_at', '<=', $until);
        }

        return $query;
    }

    #[\Override]
    public function hydrate(array $ids): array
    {
        return Ticket::query()->whereKey($ids)
            ->with('assignedEmployee.user:id,name')
            ->get()
            ->mapWithKeys(fn (Ticket $ticket): array => [$ticket->id => new TimelineEvent(
                type: 'ticket',
                id: $ticket->id,
                occurredAt: $ticket->created_at ?? Carbon::now(),
                occurredAtIsDateOnly: false,
                title: "Ticket {$ticket->ticket_number} — {$ticket->title}",
                detail: $this->detail($ticket),
                statusLabel: $ticket->status->label(),
                statusColor: $ticket->status->color(),
                icon: Heroicon::OutlinedTicket,
                amountMinor: null,
                currency: null,
                actorName: $ticket->assignedEmployee?->user?->name,
                link: route('filament.admin.resources.tickets.view', ['record' => $ticket->id]),
            )])
            ->all();
    }

    private function detail(Ticket $ticket): string
    {
        $parts = [
            str($ticket->priority->value)->headline()->toString().' priority',
            str($ticket->type->value)->headline()->toString(),
        ];

        if ($ticket->isResponseBreached()) {
            $parts[] = 'Response SLA breached';
        }

        if ($ticket->isResolutionBreached()) {
            $parts[] = 'Resolution SLA breached';
        }

        return implode(' — ', $parts);
    }
}
