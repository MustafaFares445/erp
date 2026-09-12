<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\InventoryReturnStatus;
use App\Enums\InventoryReturnType;
use App\Enums\OperationType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierDebitNoteStatus;
use App\Models\AuditLog;
use App\Models\InventoryReturn;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Purchasing reports, all reading stored figures or persisted audit evidence rather than
 * recomputing them.
 */
final readonly class PurchasingReportService
{
    /**
     * @return list<array{supplier_id: int, supplier: string, orders: int, ordered_value: float, received_value: float, outstanding_value: float}>
     */
    public function openCommitments(): array
    {
        $rows = DB::table('purchase_order_lines')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
            ->whereNull('purchase_orders.deleted_at')
            ->whereIn('purchase_orders.status', self::openStatuses())
            ->groupBy('purchase_orders.supplier_id', 'suppliers.name')
            ->select([
                'purchase_orders.supplier_id',
                'suppliers.name as supplier',
                DB::raw('COUNT(DISTINCT purchase_orders.id) as order_count'),
                DB::raw('SUM(purchase_order_lines.quantity_ordered * purchase_order_lines.unit_cost) as ordered_value'),
                DB::raw('SUM(purchase_order_lines.quantity_received * purchase_order_lines.unit_cost) as received_value'),
            ])
            ->orderBy('suppliers.name')
            ->get();

        $report = [];

        foreach ($rows as $row) {
            $ordered = round($this->toFloat($row->ordered_value ?? null), 2);
            $received = round($this->toFloat($row->received_value ?? null), 2);

            $report[] = [
                'supplier_id' => $this->toInt($row->supplier_id ?? null),
                'supplier' => $this->toString($row->supplier ?? null),
                'orders' => $this->toInt($row->order_count ?? null),
                'ordered_value' => $ordered,
                'received_value' => $received,
                'outstanding_value' => round($ordered - $received, 2),
            ];
        }

        return $report;
    }

    /**
     * @return list<array{supplier_id: int, supplier: string, promised: int, on_time: int, on_time_rate: float}>
     */
    public function receivingPerformance(): array
    {
        $confirmations = SupplierConfirmation::query()
            ->where('confirmable_type', PurchaseOrder::class)
            ->where('confirmation_status', 'confirmed')
            ->whereNotNull('promised_at')
            ->with(['supplier', 'confirmable'])
            ->get();

        $orderIds = $confirmations
            ->pluck('confirmable_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $completedAtByOrder = $orderIds->isEmpty()
            ? collect()
            : DB::table('inventory_operations')
                ->where('source_document_type', (new PurchaseOrder)->getMorphClass())
                ->where('operation_type', OperationType::Receipt->value)
                ->whereIn('source_document_id', $orderIds->all())
                ->whereNotNull('completed_at')
                ->selectRaw('source_document_id, MAX(completed_at) as completed_at')
                ->groupBy('source_document_id')
                ->pluck('completed_at', 'source_document_id');

        /** @var array<int, array{supplier_id: int, supplier: string, promised: int, on_time: int}> $bySupplier */
        $bySupplier = [];

        foreach ($confirmations as $confirmation) {
            $order = $confirmation->confirmable;

            if (! $order instanceof PurchaseOrder) {
                continue;
            }

            $completedAt = $completedAtByOrder->get($order->getKey());
            if (! is_string($completedAt) || $confirmation->promised_at === null) {
                continue;
            }

            $supplierId = $confirmation->supplier_id;

            $bySupplier[$supplierId] ??= [
                'supplier_id' => $supplierId,
                'supplier' => (string) $confirmation->supplier->name,
                'promised' => 0,
                'on_time' => 0,
            ];

            $bySupplier[$supplierId]['promised']++;

            if (mb_substr($completedAt, 0, 10) <= $confirmation->promised_at->toDateString()) {
                $bySupplier[$supplierId]['on_time']++;
            }
        }

        $report = [];

        foreach ($bySupplier as $row) {
            $report[] = [
                ...$row,
                'on_time_rate' => $row['promised'] > 0
                    ? round($row['on_time'] / $row['promised'] * 100, 1)
                    : 0.0,
            ];
        }

        return $report;
    }

    /**
     * @return list<array{purchase_order_number: string, supplier: string, variant: string, ordered_cost: float, received_cost: float, variance: float}>
     */
    public function costVariance(): array
    {
        $lines = PurchaseOrderLine::query()
            ->whereNotNull('last_received_unit_cost')
            ->whereColumn('last_received_unit_cost', '!=', 'unit_cost')
            ->with(['purchaseOrder.supplier', 'productVariant'])
            ->orderByDesc('id')
            ->get();

        $report = [];

        foreach ($lines as $line) {
            $ordered = round((float) $line->unit_cost, 2);
            $received = round((float) $line->last_received_unit_cost, 2);

            $report[] = [
                'purchase_order_number' => $line->purchaseOrder->purchase_order_number,
                'supplier' => (string) $line->purchaseOrder->supplier->name,
                'variant' => $line->productVariant->sku,
                'ordered_cost' => $ordered,
                'received_cost' => $received,
                'variance' => round($received - $ordered, 2),
            ];
        }

        return $report;
    }

    /**
     * Supplier returns whose physical stock leg is complete but whose promised
     * supplier credit/refund has not yet produced a confirmed debit note.
     *
     * @return list<array{return_id:int,return_number:string,supplier:string,expected_outcome:string,posted_at:string,age_days:int,purchase_order_id:int|null}>
     */
    public function supplierReturnsAwaitingCredit(): array
    {
        return InventoryReturn::query()
            ->where('return_type', InventoryReturnType::Supplier->value)
            ->where('status', InventoryReturnStatus::Posted->value)
            ->whereIn('expected_outcome', ['credit', 'refund'])
            ->whereDoesntHave('supplierDebitNote', fn ($query) => $query
                ->where('status', SupplierDebitNoteStatus::Confirmed->value))
            ->with('supplier:id,name')
            ->orderBy('posted_at')
            ->get()
            ->map(static function (InventoryReturn $return): array {
                $postedAt = $return->posted_at;

                return [
                    'return_id' => (int) $return->getKey(),
                    'return_number' => (string) $return->return_number,
                    'supplier' => (string) ($return->supplier?->name ?? 'Unknown supplier'),
                    'expected_outcome' => (string) ($return->expected_outcome?->value ?? ''),
                    'posted_at' => $postedAt?->format('Y-m-d H:i:s') ?? '',
                    'age_days' => $postedAt?->diffInDays(now()) ?? 0,
                    'purchase_order_id' => is_int($return->original_purchase_order_id)
                        ? $return->original_purchase_order_id
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{
     *   attempted_at:string,
     *   supplier_id:int|null,
     *   supplier:string,
     *   supplier_reference:string,
     *   attempted_by:string,
     *   message:string
     * }>
     */
    public function duplicateReferenceAttempts(): array
    {
        $logs = AuditLog::query()
            ->with('causer')
            ->where('description', 'accounting.bill.supplier_reference_rejected')
            ->latest('id')
            ->limit(500)
            ->get()
            ->filter(fn (AuditLog $log): bool => $log->getProperty('rejection_type') === 'duplicate')
            ->values();

        $supplierIds = $logs
            ->map(fn (AuditLog $log): mixed => $log->getProperty('supplier_id'))
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $suppliers = Supplier::withTrashed()
            ->whereKey($supplierIds)
            ->pluck('name', 'id');

        return $logs->map(function (AuditLog $log) use ($suppliers): array {
            $supplierId = $log->getProperty('supplier_id');
            $reference = $log->getProperty('supplier_reference');
            $message = $log->getProperty('message');
            $causer = $log->causer;
            $causerName = $causer instanceof Model
                ? $causer->getAttribute('name')
                : null;

            return [
                'attempted_at' => $log->created_at?->format('Y-m-d H:i:s') ?? '',
                'supplier_id' => is_numeric($supplierId) ? (int) $supplierId : null,
                'supplier' => is_numeric($supplierId)
                    ? (string) ($suppliers[(int) $supplierId] ?? 'Deleted supplier')
                    : 'Unknown supplier',
                'supplier_reference' => is_string($reference) ? $reference : '',
                'attempted_by' => is_string($causerName) && $causerName !== ''
                    ? $causerName
                    : 'System / unknown',
                'message' => is_string($message) ? $message : '',
            ];
        })->all();
    }

    /** @return list<string> */
    private static function openStatuses(): array
    {
        $open = [];

        foreach (PurchaseOrderStatus::cases() as $status) {
            if ($status->isTerminal() || $status === PurchaseOrderStatus::Draft) {
                continue;
            }

            $open[] = $status->value;
        }

        return $open;
    }

    private function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function toString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
