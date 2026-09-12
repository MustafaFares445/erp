<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\ChartAccount;
use App\Models\InventoryOperationLine;
use App\Models\JournalEntry;
use App\Models\PurchaseSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Clears goods-received-not-invoiced for supplier bill lines that have real,
 * completed receipt provenance.
 *
 * AccountingDocumentService keeps its existing bill approval rules and tax/AP
 * posting. This service posts one idempotent reclassification entry that
 * replaces the line expense debit with GRNI for the inventory-backed portion:
 * Dr GRNI / Cr the original bill-line account. Together with the receipt's
 * Dr Inventory / Cr GRNI, the resulting ledger is Inventory + input tax / AP.
 */
final readonly class GrniClearingService
{
    public function __construct(private JournalPostingService $journalPosting) {}

    public function clearForApprovedBill(Bill $bill): ?JournalEntry
    {
        if ($bill->status->value !== 'approved') {
            return null;
        }

        $settings = PurchaseSetting::current()->load('grniAccount');
        $grni = $settings->grniAccount;
        if (! $grni instanceof ChartAccount) {
            return null;
        }

        if (! $grni->is_active || ! $grni->is_postable) {
            throw new DomainException('The configured GRNI account must be active and postable.');
        }

        if (JournalEntry::query()
            ->where('source_type', $bill->getMorphClass())
            ->where('source_id', $bill->getKey())
            ->where('description', 'GRNI clear '.$bill->bill_number)
            ->exists()) {
            return null;
        }

        $actor = is_numeric($bill->approved_by)
            ? User::query()->find((int) $bill->approved_by)
            : null;
        if (! $actor instanceof User) {
            throw new DomainException('An approved inventory bill requires an approving actor for GRNI clearing.');
        }

        $lines = BillLine::query()
            ->where('bill_id', $bill->getKey())
            ->whereNotNull('purchase_order_line_id')
            ->whereNotNull('chart_account_id')
            ->orderBy('id')
            ->get();

        /** @var array<int, int> $creditMinorByAccount */
        $creditMinorByAccount = [];
        $grniDebitMinor = 0;

        foreach ($lines as $line) {
            if (! is_int($line->purchase_order_line_id)) {
                continue;
            }
            if (! is_int($line->chart_account_id)) {
                continue;
            }
            if (! $this->hasCompletedReceipt($line->purchase_order_line_id)) {
                continue;
            }

            if ($line->chart_account_id === (int) $grni->getKey()) {
                continue;
            }

            $minor = (int) round((float) $line->line_total * 100);
            if ($minor <= 0) {
                continue;
            }

            $creditMinorByAccount[$line->chart_account_id] = ($creditMinorByAccount[$line->chart_account_id] ?? 0) + $minor;
            $grniDebitMinor += $minor;
        }

        if ($grniDebitMinor === 0) {
            return null;
        }

        $postingLines = [[
            'chart_account_id' => (int) $grni->getKey(),
            'debit' => self::money($grniDebitMinor),
            'credit' => '0.00',
            'description' => 'Clear goods received not invoiced',
        ]];

        foreach ($creditMinorByAccount as $accountId => $minor) {
            $postingLines[] = [
                'chart_account_id' => $accountId,
                'debit' => '0.00',
                'credit' => self::money($minor),
                'description' => 'Reclassify received inventory bill line',
            ];
        }

        return DB::transaction(fn (): JournalEntry => $this->journalPosting->postNew(
            $actor,
            CarbonImmutable::parse($bill->bill_date),
            $postingLines,
            'GRNI clear '.$bill->bill_number,
            $bill,
        ));
    }

    private function hasCompletedReceipt(int $purchaseOrderLineId): bool
    {
        return InventoryOperationLine::query()
            ->where('purchase_order_line_id', $purchaseOrderLineId)
            ->whereHas('operation', fn (Builder $query): Builder => $query
                ->where('operation_type', OperationType::Receipt->value)
                ->where('stage', OperationStage::Done->value))
            ->exists();
    }

    private static function money(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
