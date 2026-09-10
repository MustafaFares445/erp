<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\BillStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\Bill;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Provisions the AP draft that is a system-owned side effect of accepting a PO.
 *
 * This service intentionally does not use BillPolicy/Accounting CRUD permission:
 * a Purchasing actor accepting a PO is not thereby granted permission to create
 * arbitrary Bills. The generated document is restricted to the accepted PO,
 * derives its supplier from that PO, and remains Draft for Accounting review.
 */
final readonly class PurchaseOrderDraftBillService
{
    public function ensureForAccepted(User $actor, PurchaseOrder $order): Bill
    {
        return DB::transaction(function () use ($actor, $order): Bill {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()
                ->with('lines')
                ->lockForUpdate()
                ->findOrFail($order->getKey());

            if ($locked->status !== PurchaseOrderStatus::Accepted) {
                throw new DomainException('A draft supplier bill can only be provisioned for an accepted purchase order.');
            }

            /** @var Bill|null $existing */
            $existing = Bill::query()
                ->where('purchase_order_id', $locked->getKey())
                ->lockForUpdate()
                ->first();

            if ($existing instanceof Bill) {
                return $existing->load('lines');
            }

            $subtotal = $this->subtotal($locked);

            $bill = new Bill([
                'purchase_order_id' => $locked->getKey(),
                // Draft bills require a reference today. Phase 5 owns relaxing
                // that rule until the supplier's real invoice reference exists.
                // This deterministic placeholder is PO-scoped and retry-safe.
                'supplier_reference' => $this->provisionalSupplierReference($locked),
                'bill_date' => now()->toDateString(),
                'description' => "Draft generated from accepted purchase order {$locked->purchase_order_number}",
                'subtotal' => $subtotal,
                'tax_total' => '0.00',
                'total_amount' => $locked->total_amount,
                'grand_total' => $locked->total_amount,
                'amount_paid' => '0.00',
                'paid_amount' => '0.00',
                'status' => BillStatus::Draft->value,
                'notes' => 'System-provisioned draft. Replace the provisional supplier reference with the supplier invoice reference before approval.',
            ]);

            $bill->forceFill([
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            foreach ($locked->lines as $index => $line) {
                $bill->lines()->create($this->lineAttributes($line, $index + 1));
            }

            activity()
                ->performedOn($bill)
                ->causedBy($actor)
                ->withProperties([
                    'source_channel' => 'system',
                    'purchase_order_id' => $locked->getKey(),
                    'purchase_order_number' => $locked->purchase_order_number,
                ])
                ->log('accounting.bill.created_from_purchase_order');

            return $bill->refresh()->load('lines');
        });
    }

    private function subtotal(PurchaseOrder $order): string
    {
        return number_format(
            $order->lines->sum(fn (PurchaseOrderLine $line): float => (float) $line->line_total),
            2,
            '.',
            '',
        );
    }

    /** @return array<string, int|string|null> */
    private function lineAttributes(PurchaseOrderLine $line, int $sortOrder): array
    {
        return [
            'purchase_order_line_id' => $line->getKey(),
            'product_variant_id' => $line->product_variant_id,
            'chart_account_id' => null,
            'description' => $line->supplier_item_number ?: "Purchase order line {$line->getKey()}",
            'quantity' => $line->quantity_ordered,
            'unit_price' => $line->unit_cost,
            'tax_amount' => '0.00',
            'line_total' => $line->line_total,
            'sort_order' => $sortOrder,
        ];
    }

    private function provisionalSupplierReference(PurchaseOrder $order): string
    {
        return 'PO-AUTO:'.$order->purchase_order_number;
    }
}
