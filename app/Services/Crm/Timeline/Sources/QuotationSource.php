<?php

declare(strict_types=1);

namespace App\Services\Crm\Timeline\Sources;

use App\Data\Crm\TimelineEvent;
use App\Enums\SalesPermission;
use App\Models\CustomerProfile;
use App\Models\JournalEntryLine;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Crm\Timeline\TimelineSource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

final readonly class QuotationSource implements TimelineSource
{
    #[\Override]
    public function key(): string
    {
        return 'quotation';
    }

    #[\Override]
    public function permission(User $actor): bool
    {
        return $actor->can(SalesPermission::QuotationView->value);
    }

    #[\Override]
    public function subQuery(CustomerProfile $customer, User $actor, ?Carbon $from, ?Carbon $until, ?string $search): QueryBuilder
    {
        $query = Quotation::query()->toBase()
            ->selectRaw("id, 'quotation' as type, issue_date as occurred_at")
            ->where('customer_id', $customer->id);

        if (is_string($search) && $search !== '') {
            $query->where('quotation_number', 'like', "%{$search}%");
        }

        if ($from instanceof Carbon) {
            $query->where('issue_date', '>=', $from);
        }

        if ($until instanceof Carbon) {
            $query->where('issue_date', '<=', $until);
        }

        return $query;
    }

    #[\Override]
    public function hydrate(array $ids): array
    {
        return Quotation::query()->whereKey($ids)
            ->with(['decidedBy:id,name', 'createdBy:id,name', 'convertedOrder:id,order_number'])
            ->get()
            ->mapWithKeys(function (Quotation $quotation): array {
                $relatedLinks = [];

                if ($quotation->convertedOrder instanceof Order) {
                    $relatedLinks[] = [
                        'label' => "→ {$quotation->convertedOrder->order_number}",
                        'url' => route('filament.admin.resources.orders.view', ['record' => $quotation->convertedOrder->id]),
                    ];
                }

                return [$quotation->id => new TimelineEvent(
                    type: 'quotation',
                    id: $quotation->id,
                    occurredAt: $quotation->issue_date,
                    occurredAtIsDateOnly: true,
                    title: "Quotation {$quotation->quotation_number}",
                    detail: $this->detail($quotation),
                    statusLabel: $quotation->status->label(),
                    statusColor: $quotation->status->color(),
                    icon: Heroicon::OutlinedDocumentText,
                    amountMinor: JournalEntryLine::toMinorUnits($quotation->grand_total),
                    currency: null,
                    actorName: match (true) {
                        $quotation->decidedBy instanceof User => $quotation->decidedBy->name,
                        $quotation->createdBy instanceof User => $quotation->createdBy->name,
                        default => null,
                    },
                    link: route('filament.admin.resources.quotations.view', ['record' => $quotation->id]),
                    relatedLinks: $relatedLinks,
                )];
            })
            ->all();
    }

    private function detail(Quotation $quotation): ?string
    {
        $parts = [];

        if ($quotation->expires_at instanceof Carbon) {
            $parts[] = "Valid until {$quotation->expires_at->toDateString()}";
        }

        if (is_string($quotation->decision_note) && $quotation->decision_note !== '') {
            $parts[] = $quotation->decision_note;
        }

        return $parts === [] ? null : implode(' — ', $parts);
    }
}
