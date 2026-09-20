<?php

declare(strict_types=1);

namespace App\Services\Crm\Timeline\Sources;

use App\Data\Crm\TimelineEvent;
use App\Enums\SalesPermission;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\Order;
use App\Models\User;
use App\Services\Crm\Timeline\TimelineSource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

final readonly class InvoiceSource implements TimelineSource
{
    #[\Override]
    public function key(): string
    {
        return 'invoice';
    }

    #[\Override]
    public function permission(User $actor): bool
    {
        return $actor->can(SalesPermission::InvoiceView->value);
    }

    #[\Override]
    public function subQuery(CustomerProfile $customer, User $actor, ?Carbon $from, ?Carbon $until, ?string $search): QueryBuilder
    {
        $query = Invoice::query()->toBase()
            ->selectRaw("id, 'invoice' as type, invoice_date as occurred_at")
            ->where('customer_id', $customer->id);

        if (is_string($search) && $search !== '') {
            $query->where('invoice_number', 'like', "%{$search}%");
        }

        if ($from instanceof Carbon) {
            $query->where('invoice_date', '>=', $from);
        }

        if ($until instanceof Carbon) {
            $query->where('invoice_date', '<=', $until);
        }

        return $query;
    }

    #[\Override]
    public function hydrate(array $ids): array
    {
        return Invoice::query()->whereKey($ids)
            ->with(['createdBy:id,name', 'order:id,order_number'])
            ->get()
            ->mapWithKeys(function (Invoice $invoice): array {
                $relatedLinks = [];

                if ($invoice->order instanceof Order) {
                    $relatedLinks[] = [
                        'label' => "← {$invoice->order->order_number}",
                        'url' => route('filament.admin.resources.orders.view', ['record' => $invoice->order->id]),
                    ];
                }

                return [$invoice->id => new TimelineEvent(
                    type: 'invoice',
                    id: $invoice->id,
                    occurredAt: $invoice->invoice_date,
                    occurredAtIsDateOnly: true,
                    title: "Invoice {$invoice->invoice_number}",
                    detail: $this->detail($invoice),
                    statusLabel: $invoice->status->label(),
                    statusColor: $invoice->status->color(),
                    icon: Heroicon::OutlinedDocumentCurrencyDollar,
                    amountMinor: JournalEntryLine::toMinorUnits($invoice->total_amount),
                    currency: null,
                    actorName: $invoice->createdBy?->name,
                    link: route('filament.admin.resources.invoices.view', ['record' => $invoice->id]),
                    relatedLinks: $relatedLinks,
                )];
            })
            ->all();
    }

    private function detail(Invoice $invoice): ?string
    {
        if (! $invoice->due_date instanceof Carbon) {
            return null;
        }

        $daysOverdue = $invoice->isOverdue() ? now()->diffInDays($invoice->due_date) : null;

        return $daysOverdue !== null
            ? "Due {$invoice->due_date->toDateString()} — {$daysOverdue} days overdue"
            : "Due {$invoice->due_date->toDateString()}";
    }
}
