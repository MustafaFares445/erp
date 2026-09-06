<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\CreditNoteStatus;
use App\Enums\InventoryReturnStatus;
use App\Enums\InventoryReturnType;
use App\Enums\InvoiceStatus;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\OpportunityCloseReason;
use App\Enums\OpportunityStage;
use App\Enums\QuotationStatus;
use App\Enums\SalesReportType;
use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\InventoryReturn;
use App\Models\Invoice;
use App\Models\InvoiceDeliveryLink;
use App\Models\JournalEntryLine;
use App\Models\Order;
use App\Models\PaymentAllocation;
use App\Models\PriceFloorOverride;
use App\Models\Quotation;
use App\Models\SalesOpportunity;
use App\Models\User;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Accounting\TaxRegisterService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Read-only sales reporting surface (WP-2.8, GAP-MW-17, GAP-UI-05, SL-15).
 *
 * Every method here is a pure query: no method posts, mutates, or persists anything.
 * {@see self::invoicedNotCollected()} and {@see self::taxRecognitionSummary()} deliberately
 * delegate to {@see AccountsReceivableService} and {@see TaxRegisterService} respectively,
 * rather than re-deriving figures those services already own — XC-04's "no report may compute a
 * business figure by a rule that disagrees with the document that owns it."
 */
final readonly class SalesReportService
{
    public function __construct(
        private AccountsReceivableService $receivables,
        private TaxRegisterService $taxRegister,
    ) {}

    public function canView(User $actor, SalesReportType $type): bool
    {
        return $actor->can($type->sourcePermission()->value);
    }

    /** @return array<string, mixed> */
    public function quotationFunnel(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $query = Quotation::query()->with(['customer', 'employee']);
        if ($from instanceof CarbonInterface) {
            $query->whereDate('issue_date', '>=', $from->toDateString());
        }
        if ($to instanceof CarbonInterface) {
            $query->whereDate('issue_date', '<=', $to->toDateString());
        }
        $quotations = $query->get();

        $byStatus = $quotations->groupBy(fn (Quotation $quotation): string => $quotation->status->value)
            ->map(fn (Collection $group): int => $group->count())
            ->all();

        $byEmployee = $quotations->groupBy('employee_id')
            ->map(fn (Collection $group, int|string $employeeId): array => [
                'employee_id' => $employeeId === '' ? null : (int) $employeeId,
                'count' => $group->count(),
                'accepted' => $group->where('status', QuotationStatus::Accepted)->count(),
            ])
            ->values()
            ->all();

        $byCustomer = $quotations->groupBy('customer_id')
            ->map(fn (Collection $group, int|string $customerId): array => [
                'customer_id' => (int) $customerId,
                'count' => $group->count(),
                'accepted' => $group->where('status', QuotationStatus::Accepted)->count(),
            ])
            ->values()
            ->all();

        return [
            'total' => $quotations->count(),
            'by_status' => $byStatus,
            'by_employee' => $byEmployee,
            'by_customer' => $byCustomer,
        ];
    }

    /** @return array<string, mixed> */
    public function winLossAnalysis(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $query = SalesOpportunity::query()
            ->whereIn('stage', [OpportunityStage::ClosedWon->value, OpportunityStage::ClosedLost->value]);
        if ($from instanceof CarbonInterface) {
            $query->whereDate('closed_at', '>=', $from->toDateString());
        }
        if ($to instanceof CarbonInterface) {
            $query->whereDate('closed_at', '<=', $to->toDateString());
        }
        $opportunities = $query->get();

        $won = $opportunities->filter(fn (SalesOpportunity $o): bool => $o->stage === OpportunityStage::ClosedWon);
        $lost = $opportunities->filter(fn (SalesOpportunity $o): bool => $o->stage === OpportunityStage::ClosedLost);
        $total = $opportunities->count();

        $lossReasons = $lost->groupBy(function (SalesOpportunity $o): string {
            $reason = $o->close_reason;

            return $reason instanceof OpportunityCloseReason ? $reason->value : 'unspecified';
        })
            ->map(fn (Collection $group, string $reason): array => ['reason' => $reason, 'count' => $group->count()])
            ->values()
            ->sortByDesc('count')
            ->values()
            ->all();

        $byOwner = $opportunities->groupBy('owner_id')
            ->map(function (Collection $group, int|string $ownerId): array {
                $ownerWon = $group->where('stage', OpportunityStage::ClosedWon)->count();
                $ownerTotal = $group->count();

                return [
                    'owner_id' => $ownerId === '' ? null : (int) $ownerId,
                    'won' => $ownerWon,
                    'lost' => $ownerTotal - $ownerWon,
                    'win_rate_percent' => $ownerTotal > 0 ? round($ownerWon / $ownerTotal * 100, 2) : 0.0,
                ];
            })
            ->values()
            ->all();

        return [
            'total_closed' => $total,
            'won_count' => $won->count(),
            'lost_count' => $lost->count(),
            'win_rate_percent' => $total > 0 ? round($won->count() / $total * 100, 2) : 0.0,
            'loss_reasons' => $lossReasons,
            'by_owner' => $byOwner,
        ];
    }

    /** @return array<string, mixed> */
    public function conversionVelocity(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $query = Quotation::query()->whereNotNull('sent_at');
        if ($from instanceof CarbonInterface) {
            $query->whereDate('sent_at', '>=', $from->toDateString());
        }
        if ($to instanceof CarbonInterface) {
            $query->whereDate('sent_at', '<=', $to->toDateString());
        }
        $quotations = $query->get();

        /** @var list<int> $sentToDecidedDays */
        $sentToDecidedDays = [];
        /** @var list<int> $acceptedToConvertedDays */
        $acceptedToConvertedDays = [];

        foreach ($quotations as $quotation) {
            $sentAt = $quotation->sent_at;
            $decidedAt = $quotation->decided_at;

            if ($sentAt instanceof CarbonInterface && $decidedAt instanceof CarbonInterface) {
                $sentToDecidedDays[] = (int) $sentAt->diffInDays($decidedAt, false);
            }

            $order = $quotation->convertedOrder;
            if ($decidedAt instanceof CarbonInterface && $order instanceof Order) {
                $acceptedToConvertedDays[] = (int) $decidedAt->diffInDays($order->created_at, false);
            }
        }

        return [
            'median_days_sent_to_decided' => self::median($sentToDecidedDays),
            'sample_size_sent_to_decided' => count($sentToDecidedDays),
            'median_days_accepted_to_converted' => self::median($acceptedToConvertedDays),
            'sample_size_accepted_to_converted' => count($acceptedToConvertedDays),
        ];
    }

    /**
     * Leak point 1 (SL-15): a completed delivery with no {@see InvoiceDeliveryLink}. A
     * standalone invoice that references no delivery creates no link row, so it can never hide a
     * delivery from this report (GAP-MW-13).
     *
     * @return array<string, mixed>
     */
    public function deliveredNotInvoiced(?CarbonInterface $asOf = null): array
    {
        $date = $this->asOfDate($asOf);

        $deliveries = InventoryOperation::query()
            ->where('operation_type', OperationType::Delivery->value)
            ->where('stage', OperationStage::Done->value)
            ->whereNotNull('completed_at')
            ->whereDate('completed_at', '<=', $date->toDateString())
            ->whereDoesntHave('invoiceDeliveryLink')
            ->with('customer')
            ->get();

        $rows = $deliveries->map(function (InventoryOperation $delivery) use ($date): array {
            $completedAt = $delivery->completed_at;
            $customer = $delivery->customer;
            $customerId = $delivery->customer_id;

            return [
                'inventory_operation_id' => (int) $delivery->id,
                'operation_number' => (string) $delivery->operation_number,
                'customer_id' => $customerId !== null ? (int) $customerId : null,
                'customer_name' => self::customerLabel($customer, $customerId),
                'completed_at' => $completedAt?->toDateString(),
                'days_outstanding' => $completedAt instanceof CarbonInterface ? (int) max(0, $completedAt->diffInDays($date, false)) : 0,
            ];
        })->values()->all();

        return [
            'as_of' => $date->toDateString(),
            'deliveries' => $rows,
            'count' => count($rows),
        ];
    }

    /**
     * Leak point 2 (SL-15): delegates entirely to {@see AccountsReceivableService::aging()} so
     * this figure can never drift from the authoritative AR calculation for the same as-of date.
     *
     * @return array<string, mixed>
     */
    public function invoicedNotCollected(?CarbonInterface $asOf = null): array
    {
        return $this->receivables->aging($asOf);
    }

    /**
     * Delegates entirely to {@see TaxRegisterService::period()} rather than recomputing tax.
     *
     * @return array<string, mixed>
     */
    public function taxRecognitionSummary(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->taxRegister->period($from, $to);
    }

    /**
     * Surfaces persisted price-floor override provenance (WP-2.3) as-is — no recomputation.
     *
     * @return array<string, mixed>
     */
    public function discountAndFloorOverrides(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $query = PriceFloorOverride::query()->with(['productVariant', 'approvedBy', 'customer']);
        if ($from instanceof CarbonInterface) {
            $query->whereDate('approved_at', '>=', $from->toDateString());
        }
        if ($to instanceof CarbonInterface) {
            $query->whereDate('approved_at', '<=', $to->toDateString());
        }
        $overrides = $query->orderByDesc('approved_at')->get();

        $rows = $overrides->map(fn (PriceFloorOverride $override): array => [
            'id' => (int) $override->id,
            'product_variant_id' => (int) $override->product_variant_id,
            'customer_user_id' => $override->customer_user_id !== null ? (int) $override->customer_user_id : null,
            'attempted_price' => (string) $override->attempted_price,
            'min_price' => (string) $override->min_price,
            'approved_by' => (int) $override->approved_by,
            'approved_by_name' => $override->approvedBy?->name,
            'approved_at' => $override->approved_at->toDateTimeString(),
            'reason' => $override->reason,
        ])->values()->all();

        return [
            'overrides' => $rows,
            'count' => count($rows),
        ];
    }

    /**
     * Posted customer returns lacking a CONFIRMED, unreversed linked credit note. A draft credit
     * note does not count as coverage — it has not corrected anything yet.
     *
     * @return array<string, mixed>
     */
    public function returnsWithoutCredit(?CarbonInterface $asOf = null): array
    {
        $date = $this->asOfDate($asOf);

        $returns = InventoryReturn::query()
            ->where('return_type', InventoryReturnType::Customer->value)
            ->where('status', InventoryReturnStatus::Posted->value)
            ->whereNotNull('posted_at')
            ->whereDate('posted_at', '<=', $date->toDateString())
            ->whereDoesntHave('creditNotes', fn ($q) => $q->where('status', CreditNoteStatus::Confirmed->value)
                ->whereNull('reversed_at'))
            ->with('customer')
            ->get();

        $rows = $returns->map(function (InventoryReturn $return) use ($date): array {
            $postedAt = $return->posted_at;
            $customer = $return->customer;
            $customerId = $return->customer_id;

            return [
                'inventory_return_id' => (int) $return->id,
                'return_number' => (string) $return->return_number,
                'customer_id' => $customerId !== null ? (int) $customerId : null,
                'customer_name' => self::customerLabel($customer, $customerId),
                'posted_at' => $postedAt?->toDateString(),
                'days_outstanding' => $postedAt instanceof CarbonInterface ? (int) max(0, $postedAt->diffInDays($date, false)) : 0,
            ];
        })->values()->all();

        return [
            'as_of' => $date->toDateString(),
            'returns' => $rows,
            'count' => count($rows),
        ];
    }

    /**
     * Revenue by customer: invoiced (issued, non-cancelled) versus collected (posted, unreversed
     * payment allocations) as of a date.
     *
     * @return array<string, mixed>
     */
    public function customerRevenue(?CarbonInterface $asOf = null): array
    {
        $date = $this->asOfDate($asOf);

        $invoices = Invoice::query()
            ->withTrashed()
            ->with(['customer', 'paymentAllocations.payment'])
            ->whereNotNull('issued_at')
            ->whereDate('issued_at', '<=', $date->toDateString())
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->get();

        $grouped = $invoices->groupBy('customer_id')->map(function (Collection $group, int|string $customerId) use ($date): array {
            $invoicedMinor = $group->sum(fn (Invoice $invoice): int => JournalEntryLine::toMinorUnits($invoice->total_amount));
            $collectedMinor = $group->sum(function (Invoice $invoice) use ($date): int {
                return $invoice->paymentAllocations
                    ->filter(function (PaymentAllocation $allocation) use ($date): bool {
                        $payment = $allocation->payment;

                        return $payment !== null
                            && $payment->posted_at !== null
                            && $payment->posted_at->lessThanOrEqualTo($date)
                            && ($payment->reversed_at === null || $payment->reversed_at->greaterThan($date));
                    })
                    ->sum(fn (PaymentAllocation $allocation): int => JournalEntryLine::toMinorUnits($allocation->amount));
            });

            $firstInvoice = $group->first();
            $customer = $firstInvoice?->customer;

            return [
                'customer_id' => (int) $customerId,
                'customer_name' => self::customerLabel($customer, is_numeric($customerId) ? (int) $customerId : null),
                'invoiced_minor' => (int) $invoicedMinor,
                'collected_minor' => (int) $collectedMinor,
            ];
        })->values()->sortByDesc('invoiced_minor')->values()->all();

        return [
            'as_of' => $date->toDateString(),
            'customers' => $grouped,
        ];
    }

    /** @return array<string, mixed> */
    public function report(SalesReportType $type, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        return match ($type) {
            SalesReportType::QuotationFunnel => $this->quotationFunnel($from, $to),
            SalesReportType::WinLossAnalysis => $this->winLossAnalysis($from, $to),
            SalesReportType::ConversionVelocity => $this->conversionVelocity($from, $to),
            SalesReportType::DeliveredNotInvoiced => $this->deliveredNotInvoiced($to),
            SalesReportType::InvoicedNotCollected => $this->invoicedNotCollected($to),
            SalesReportType::TaxRecognitionSummary => $this->taxRecognitionSummary($from ?? CarbonImmutable::today()->startOfYear(), $to ?? CarbonImmutable::today()),
            SalesReportType::DiscountAndFloorOverrides => $this->discountAndFloorOverrides($from, $to),
            SalesReportType::ReturnsWithoutCredit => $this->returnsWithoutCredit($to),
            SalesReportType::CustomerRevenue => $this->customerRevenue($to),
        };
    }

    private function asOfDate(?CarbonInterface $asOf): CarbonImmutable
    {
        return $asOf instanceof CarbonInterface
            ? CarbonImmutable::instance($asOf)->endOfDay()
            : CarbonImmutable::today()->endOfDay();
    }

    private static function customerLabel(?CustomerProfile $customer, ?int $customerId): string
    {
        if ($customer instanceof CustomerProfile) {
            return $customer->company_name ?: ($customer->customer_code ?: "Customer #{$customerId}");
        }

        return $customerId !== null ? "Customer #{$customerId}" : 'Unknown customer';
    }

    /** @param list<int> $values */
    private static function median(array $values): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        sort($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return (float) $values[$middle];
    }
}
