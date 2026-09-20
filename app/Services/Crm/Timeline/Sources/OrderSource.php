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

final readonly class OrderSource implements TimelineSource
{
    #[\Override]
    public function key(): string
    {
        return 'order';
    }

    #[\Override]
    public function permission(User $actor): bool
    {
        return $actor->can(SalesPermission::OrderView->value);
    }

    #[\Override]
    public function subQuery(CustomerProfile $customer, User $actor, ?Carbon $from, ?Carbon $until, ?string $search): QueryBuilder
    {
        $query = Order::query()->toBase()
            ->selectRaw("id, 'order' as type, created_at as occurred_at")
            ->where('customer_id', $customer->id);

        if (is_string($search) && $search !== '') {
            $query->where('order_number', 'like', "%{$search}%");
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
        return Order::query()->whereKey($ids)
            ->with(['responsible:id,name', 'createdBy:id,name', 'quotation:id,quotation_number'])
            ->get()
            ->mapWithKeys(function (Order $order): array {
                $relatedLinks = [];

                if ($order->quotation instanceof Quotation) {
                    $relatedLinks[] = [
                        'label' => "← {$order->quotation->quotation_number}",
                        'url' => route('filament.admin.resources.quotations.view', ['record' => $order->quotation->id]),
                    ];
                }

                return [$order->id => new TimelineEvent(
                    type: 'order',
                    id: $order->id,
                    occurredAt: $order->created_at ?? Carbon::now(),
                    occurredAtIsDateOnly: false,
                    title: "Order {$order->order_number}",
                    detail: $this->detail($order),
                    statusLabel: $order->status->label(),
                    statusColor: $order->status->color(),
                    icon: Heroicon::OutlinedShoppingCart,
                    amountMinor: JournalEntryLine::toMinorUnits($order->grand_total),
                    currency: null,
                    actorName: match (true) {
                        $order->responsible instanceof User => $order->responsible->name,
                        $order->createdBy instanceof User => $order->createdBy->name,
                        default => null,
                    },
                    link: route('filament.admin.resources.orders.view', ['record' => $order->id]),
                    relatedLinks: $relatedLinks,
                )];
            })
            ->all();
    }

    private function detail(Order $order): ?string
    {
        $parts = [];

        if (is_string($order->delivery_type) && $order->delivery_type !== '') {
            $parts[] = str($order->delivery_type)->replace('_', ' ')->headline()->toString();
        }

        if ($order->scheduled_at instanceof Carbon) {
            $parts[] = "Scheduled {$order->scheduled_at->toDateTimeString()}";
        }

        return $parts === [] ? null : implode(' — ', $parts);
    }
}
