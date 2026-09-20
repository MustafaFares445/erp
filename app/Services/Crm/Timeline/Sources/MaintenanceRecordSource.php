<?php

declare(strict_types=1);

namespace App\Services\Crm\Timeline\Sources;

use App\Data\Crm\TimelineEvent;
use App\Enums\SupportPermission;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\MaintenanceRecord;
use App\Models\ProductVariant;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Crm\Timeline\TimelineSource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

final readonly class MaintenanceRecordSource implements TimelineSource
{
    #[\Override]
    public function key(): string
    {
        return 'maintenance_record';
    }

    #[\Override]
    public function permission(User $actor): bool
    {
        return $actor->can(SupportPermission::MaintenanceRequestView->value);
    }

    #[\Override]
    public function subQuery(CustomerProfile $customer, User $actor, ?Carbon $from, ?Carbon $until, ?string $search): QueryBuilder
    {
        $query = MaintenanceRecord::query()->toBase()
            ->selectRaw("id, 'maintenance_record' as type, created_at as occurred_at")
            ->where('customer_id', $customer->id);

        if (is_string($search) && $search !== '') {
            $query->whereRaw('1 = 0');
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
        return MaintenanceRecord::query()->whereKey($ids)
            ->with(['createdBy:id,name', 'productVariant:id,sku', 'ticket:id,ticket_number', 'invoice:id,invoice_number'])
            ->get()
            ->mapWithKeys(function (MaintenanceRecord $record): array {
                $relatedLinks = [];

                if ($record->ticket instanceof Ticket) {
                    $relatedLinks[] = [
                        'label' => "← {$record->ticket->ticket_number}",
                        'url' => route('filament.admin.resources.tickets.view', ['record' => $record->ticket->id]),
                    ];
                }

                if ($record->invoice instanceof Invoice) {
                    $relatedLinks[] = [
                        'label' => "→ {$record->invoice->invoice_number}",
                        'url' => route('filament.admin.resources.invoices.view', ['record' => $record->invoice->id]),
                    ];
                }

                $subject = match (true) {
                    $record->productVariant instanceof ProductVariant => $record->productVariant->sku,
                    is_string($record->serial_number) && $record->serial_number !== '' => $record->serial_number,
                    default => 'unlinked equipment',
                };

                return [$record->id => new TimelineEvent(
                    type: 'maintenance_record',
                    id: $record->id,
                    occurredAt: $record->created_at ?? Carbon::now(),
                    occurredAtIsDateOnly: false,
                    title: "Maintenance #{$record->id} — {$subject}",
                    detail: str($record->billing_type->value)->headline()->toString().' — '.str($record->warranty_status->value)->headline()->toString().' warranty',
                    statusLabel: $record->status->label(),
                    statusColor: $record->status->color(),
                    icon: Heroicon::OutlinedWrenchScrewdriver,
                    amountMinor: null,
                    currency: null,
                    actorName: $record->createdBy?->name,
                    link: route('filament.admin.resources.maintenance-requests.view', ['record' => $record->id]),
                    relatedLinks: $relatedLinks,
                )];
            })
            ->all();
    }
}
