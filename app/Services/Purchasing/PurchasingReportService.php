<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\AuditLog;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Purchasing reports, all reading stored figures or persisted audit evidence rather than
 * recomputing them.
 *
 * This is what R-008 bought. Because `line_total` and `total_amount` are stored
 * columns, open commitments is an indexed aggregate over `(status, supplier_id)`
 * instead of a per-row computation summed in PHP.
 *
 * The aggregates go through the query builder rather than Eloquent on purpose:
 * a grouped row is not a `PurchaseOrderLine`, and hydrating one would invite
 * code to treat a sum as a model.
 *
 * @see /specs/017-purchasing-orders-suppliers/spec.md User Story 7
 */
final readonly class PurchasingReportService
{
    /**
     * What is still owed to suppliers: ordered value minus received value, for
     * every order that is neither terminal nor still a draft.
     *
     * Drafts are excluded because nothing has been committed to yet, and
     * terminal orders because nothing more will arrive — a short-closed order's
     * outstanding quantity was deliberately abandoned, not forgotten.
     *
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
     * Whether suppliers delivered by the date they promised.
     *
     * Measured against `promised_at` on a confirmed confirmation rather than the
     * buyer's own `expected_at`: the supplier is accountable for what they
     * committed to, not for what the buyer hoped for. Orders with no confirmed
     * promise are excluded rather than counted as on-time, because there is
     * nothing to have missed.
     *
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

        /** @var array<int, array{supplier_id: int, supplier: string, promised: int, on_time: int}> $bySupplier */
        $bySupplier = [];

        foreach ($confirmations as $confirmation) {
            $order = $confirmation->confirmable;

            if (! $order instanceof PurchaseOrder) {
                continue;
            }

            $completedAt = $order->receipts()->whereNotNull('completed_at')->max('completed_at');
            if (! is_string($completedAt)) {
                continue;
            }

            if ($confirmation->promised_at === null) {
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
     * Where the price paid differed from the price ordered.
     *
     * Only lines with a recorded `last_received_unit_cost` appear: a line that
     * has not been received has no actual cost to compare against, and showing
     * it at zero variance would suggest a match that has not happened.
     *
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
     * Duplicate supplier invoice references refused by the accounting control.
     *
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

    /**
     * The statuses that represent a live commitment to a supplier.
     *
     * @return list<string>
     */
    private static function openStatuses(): array
    {
        $open = [];

        foreach (PurchaseOrderStatus::cases() as $status) {
            if ($status->isTerminal()) {
                continue;
            }

            if ($status === PurchaseOrderStatus::Draft) {
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
