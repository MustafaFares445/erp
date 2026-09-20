<?php

declare(strict_types=1);

namespace App\Services\Crm\Timeline\Sources;

use App\Data\Crm\TimelineEvent;
use App\Enums\SalesPermission;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\User;
use App\Services\Crm\Timeline\TimelineSource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

final readonly class PaymentSource implements TimelineSource
{
    #[\Override]
    public function key(): string
    {
        return 'payment';
    }

    #[\Override]
    public function permission(User $actor): bool
    {
        return $actor->can(SalesPermission::PaymentView->value);
    }

    #[\Override]
    public function subQuery(CustomerProfile $customer, User $actor, ?Carbon $from, ?Carbon $until, ?string $search): QueryBuilder
    {
        $query = Payment::query()->toBase()
            ->selectRaw("id, 'payment' as type, payment_date as occurred_at")
            ->where('customer_id', $customer->id);

        if (is_string($search) && $search !== '') {
            $query->where(function (QueryBuilder $query) use ($search): void {
                $query->where('payment_number', 'like', "%{$search}%")
                    ->orWhere('external_reference', 'like', "%{$search}%");
            });
        }

        if ($from instanceof Carbon) {
            $query->where('payment_date', '>=', $from);
        }

        if ($until instanceof Carbon) {
            $query->where('payment_date', '<=', $until);
        }

        return $query;
    }

    #[\Override]
    public function hydrate(array $ids): array
    {
        return Payment::query()->whereKey($ids)
            ->with(['createdBy:id,name', 'paymentMethod:id,name', 'allocations.invoice:id,invoice_number'])
            ->get()
            ->mapWithKeys(function (Payment $payment): array {
                $relatedLinks = [];

                foreach ($payment->allocations as $allocation) {
                    if (! $allocation->invoice instanceof Invoice) {
                        continue;
                    }

                    $relatedLinks[] = [
                        'label' => "→ {$allocation->invoice->invoice_number}",
                        'url' => route('filament.admin.resources.invoices.view', ['record' => $allocation->invoice->id]),
                    ];
                }

                return [$payment->id => new TimelineEvent(
                    type: 'payment',
                    id: $payment->id,
                    occurredAt: $payment->payment_date,
                    occurredAtIsDateOnly: true,
                    title: "Payment {$payment->payment_number}",
                    detail: $this->detail($payment),
                    statusLabel: $payment->status->label(),
                    statusColor: $payment->status->color(),
                    icon: Heroicon::OutlinedBanknotes,
                    amountMinor: JournalEntryLine::toMinorUnits($payment->amount),
                    currency: $payment->currency,
                    actorName: $payment->createdBy?->name,
                    link: route('filament.admin.resources.payments.view', ['record' => $payment->id]),
                    relatedLinks: $relatedLinks,
                )];
            })
            ->all();
    }

    private function detail(Payment $payment): ?string
    {
        $parts = [$payment->paymentMethod?->name];

        if (is_string($payment->external_reference) && $payment->external_reference !== '') {
            $parts[] = "Ref {$payment->external_reference}";
        }

        $parts = array_filter($parts, fn (?string $part): bool => is_string($part) && $part !== '');

        return $parts === [] ? null : implode(' — ', $parts);
    }
}
