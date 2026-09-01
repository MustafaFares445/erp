<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseSetting;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\QuantityNormalizer;
use Illuminate\Database\Seeder;

/**
 * Demo purchasing data: an order in every status, confirmations against both
 * document types, and one order carrying a received line.
 *
 * Idempotent by purchase order number, so re-running neither duplicates nor
 * rewrites. It writes rows directly rather than driving the services, because
 * the services enforce a lifecycle a seeder has no business walking — reaching
 * `partially_received` legitimately would mean completing a real inventory
 * operation, and a seeder that moves stock is a seeder that surprises people.
 */
final class PurchasingDemoSeeder extends Seeder
{
    public function run(): void
    {
        $supplier = Supplier::query()->where('is_active', true)->first();
        $warehouse = Warehouse::query()->where('is_active', true)->first();
        $variant = ProductVariant::query()->with('unit')->first();
        $unit = $variant?->unit;

        if (! $supplier instanceof Supplier
            || ! $warehouse instanceof Warehouse
            || ! $variant instanceof ProductVariant
            || ! $unit instanceof Unit) {
            // Nothing to hang purchasing data off. The inventory demo seeder
            // runs first in DatabaseSeeder; if it was skipped, skip too rather
            // than inventing a catalogue.
            return;
        }

        PurchaseSetting::current();

        $buyer = User::query()->where('email', 'admin@ierp.com')->first();

        $this->seedReference($supplier, $variant);

        foreach ($this->orderBlueprints() as $index => [$status, $received]) {
            $order = $this->seedOrder(
                sprintf('PO-DEMO%02d', $index + 1),
                $status,
                $supplier,
                $warehouse,
                $buyer,
            );

            $this->seedLine($order, $variant, $unit, $received);
        }

        $this->seedConfirmations($supplier);
    }

    /**
     * One order per status, so every badge and filter has something behind it.
     *
     * @return list<array{0: PurchaseOrderStatus, 1: float}>
     */
    private function orderBlueprints(): array
    {
        return [
            [PurchaseOrderStatus::Draft, 0],
            [PurchaseOrderStatus::PendingApproval, 0],
            [PurchaseOrderStatus::Approved, 0],
            [PurchaseOrderStatus::Rejected, 0],
            [PurchaseOrderStatus::Sent, 0],
            [PurchaseOrderStatus::PartiallyReceived, 4],
            [PurchaseOrderStatus::Received, 10],
            [PurchaseOrderStatus::Closed, 6],
            [PurchaseOrderStatus::Cancelled, 0],
        ];
    }

    private function seedOrder(
        string $number,
        PurchaseOrderStatus $status,
        Supplier $supplier,
        Warehouse $warehouse,
        ?User $buyer,
    ): PurchaseOrder {
        /** @var PurchaseOrder $order */
        $order = PurchaseOrder::withTrashed()->firstOrNew(['purchase_order_number' => $number]);

        $order->forceFill([
            'purchase_order_number' => $number,
            'supplier_id' => $supplier->getKey(),
            'destination_warehouse_id' => $warehouse->getKey(),
            'status' => $status,
            'currency_code' => 'AED',
            'ordered_at' => now()->subDays(14)->toDateString(),
            'expected_at' => now()->addDays(7)->toDateString(),
            'total_amount' => '250.00',
            'submitted_by' => $status === PurchaseOrderStatus::Draft ? null : $buyer?->getKey(),
            'submitted_at' => $status === PurchaseOrderStatus::Draft ? null : now()->subDays(13),
            'approved_by' => $this->isApproved($status) ? $buyer?->getKey() : null,
            'approved_at' => $this->isApproved($status) ? now()->subDays(12) : null,
            'sent_at' => $this->isSent($status) ? now()->subDays(11) : null,
            'closed_at' => $status === PurchaseOrderStatus::Closed ? now()->subDay() : null,
            'closure_reason' => $status === PurchaseOrderStatus::Closed ? 'Supplier discontinued the remaining line.' : null,
            'cancelled_at' => $status === PurchaseOrderStatus::Cancelled ? now()->subDays(10) : null,
            'cancellation_reason' => $status === PurchaseOrderStatus::Cancelled ? 'Ordered in error.' : null,
            'rejection_reason' => $status === PurchaseOrderStatus::Rejected ? 'Quoted above budget.' : null,
            'created_by' => $buyer?->getKey(),
            'updated_by' => $buyer?->getKey(),
        ])->save();

        return $order->refresh();
    }

    private function seedLine(PurchaseOrder $order, ProductVariant $variant, Unit $unit, float $received): void
    {
        $normalizer = app(QuantityNormalizer::class);
        $ordered = $normalizer->normalize($variant, $unit->getKey(), '10');
        $receivedSnapshot = $received > 0
            ? $normalizer->normalize(
                $variant,
                $unit->getKey(),
                rtrim(rtrim(number_format($received, 6, '.', ''), '0'), '.'),
            )
            : null;

        $line = $order->lines()->firstOrNew([
            'product_variant_id' => $variant->getKey(),
            'unit_id' => $unit->getKey(),
        ]);

        $line->forceFill([
            'purchase_order_id' => $order->getKey(),
            'product_variant_id' => $variant->getKey(),
            'unit_id' => $ordered->transactionUnitId,
            'quantity_ordered' => $ordered->transactionQuantity,
            'quantity_received' => $receivedSnapshot?->transactionQuantity ?? '0.000000',
            'transaction_quantity' => $ordered->transactionQuantity,
            'transaction_unit_id' => $ordered->transactionUnitId,
            'conversion_factor_snapshot' => $ordered->conversionFactorSnapshot,
            'base_quantity' => $ordered->baseQuantity,
            'received_base_quantity' => $receivedSnapshot?->baseQuantity ?? '0.000000',
            'unit_cost' => '25.00',
            'last_received_unit_cost' => $received > 0 ? '26.50' : null,
            'line_total' => '250.00',
        ])->save();
    }

    private function seedReference(Supplier $supplier, ProductVariant $variant): void
    {
        SupplierProductReference::query()->updateOrCreate(
            [
                'supplier_id' => $supplier->getKey(),
                'product_variant_id' => $variant->getKey(),
            ],
            [
                'supplier_item_number' => 'DEMO-'.$variant->id,
                'purchase_cost' => '25.00',
                'currency_code' => 'AED',
                'is_active' => true,
            ],
        );
    }

    /**
     * Confirmations against both target types, which is the point of the morph.
     */
    private function seedConfirmations(Supplier $supplier): void
    {
        $purchaseOrder = PurchaseOrder::query()->where('purchase_order_number', 'PO-DEMO05')->first();

        if ($purchaseOrder instanceof PurchaseOrder) {
            $this->seedConfirmation($purchaseOrder, $supplier, SupplierConfirmationStatus::Confirmed, 'Confirmed by phone.');
        }

        $customerOrder = Order::query()->first();

        if ($customerOrder instanceof Order) {
            $this->seedConfirmation($customerOrder, $supplier, SupplierConfirmationStatus::Pending, 'Waiting on supplier stock.');
        }
    }

    private function seedConfirmation(
        Order|PurchaseOrder $target,
        Supplier $supplier,
        SupplierConfirmationStatus $status,
        string $notes,
    ): void {
        SupplierConfirmation::query()->updateOrCreate(
            [
                'confirmable_type' => $target::class,
                'confirmable_id' => $target->getKey(),
                'supplier_id' => $supplier->getKey(),
            ],
            [
                'confirmation_status' => $status,
                'promised_at' => $status->isAnswered() ? now()->addDays(5)->toDateString() : null,
                'confirmed_at' => $status->isAnswered() ? now()->subDays(2) : null,
                'notes' => $notes,
            ],
        );
    }

    private function isApproved(PurchaseOrderStatus $status): bool
    {
        return ! in_array($status, [
            PurchaseOrderStatus::Draft,
            PurchaseOrderStatus::PendingApproval,
            PurchaseOrderStatus::Rejected,
        ], true);
    }

    private function isSent(PurchaseOrderStatus $status): bool
    {
        return in_array($status, [
            PurchaseOrderStatus::Sent,
            PurchaseOrderStatus::PartiallyReceived,
            PurchaseOrderStatus::Received,
            PurchaseOrderStatus::Closed,
        ], true);
    }
}
