<?php

declare(strict_types=1);

namespace App\Services\Crm\Timeline\Sources;

use App\Data\Crm\TimelineEvent;
use App\Enums\CrmPermission;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\Interaction;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Crm\Timeline\TimelineSource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

final readonly class InteractionSource implements TimelineSource
{
    #[\Override]
    public function key(): string
    {
        return 'interaction';
    }

    #[\Override]
    public function permission(User $actor): bool
    {
        return $actor->can(CrmPermission::InteractionView->value);
    }

    #[\Override]
    public function subQuery(CustomerProfile $customer, User $actor, ?Carbon $from, ?Carbon $until, ?string $search): QueryBuilder
    {
        $query = Interaction::query()->toBase()
            ->selectRaw("id, 'interaction' as type, occurred_at as occurred_at")
            ->where('subject_type', CustomerProfile::class)
            ->where('subject_id', $customer->id);

        if (is_string($search) && $search !== '') {
            $query->where('summary', 'like', "%{$search}%");
        }

        if ($from instanceof Carbon) {
            $query->where('occurred_at', '>=', $from);
        }

        if ($until instanceof Carbon) {
            $query->where('occurred_at', '<=', $until);
        }

        return $query;
    }

    #[\Override]
    public function hydrate(array $ids): array
    {
        return Interaction::query()->whereKey($ids)
            ->with(['employee:id,name', 'customerVisit:id', 'ticket:id,ticket_number'])
            ->get()
            ->mapWithKeys(function (Interaction $interaction): array {
                $relatedLinks = [];

                if ($interaction->ticket instanceof Ticket) {
                    $relatedLinks[] = [
                        'label' => "→ {$interaction->ticket->ticket_number}",
                        'url' => route('filament.admin.resources.tickets.view', ['record' => $interaction->ticket->id]),
                    ];
                }

                if ($interaction->customerVisit instanceof CustomerVisit) {
                    $relatedLinks[] = [
                        'label' => '→ Visit',
                        'url' => route('filament.admin.resources.visits.view', ['record' => $interaction->customerVisit->id]),
                    ];
                }

                return [$interaction->id => new TimelineEvent(
                    type: 'interaction',
                    id: $interaction->id,
                    occurredAt: $interaction->occurred_at,
                    occurredAtIsDateOnly: false,
                    title: $interaction->type->label().' — '.str($interaction->direction->value)->headline(),
                    detail: $interaction->summary,
                    statusLabel: null,
                    statusColor: $interaction->type->color(),
                    icon: Heroicon::OutlinedChatBubbleLeftRight,
                    amountMinor: null,
                    currency: null,
                    actorName: $interaction->employee?->name,
                    link: $relatedLinks[0]['url'] ?? null,
                    relatedLinks: $relatedLinks,
                )];
            })
            ->all();
    }
}
