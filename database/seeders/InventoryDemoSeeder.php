<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MovementType;
use App\Models\InventoryAdjustment;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Database\Seeder;

final class InventoryDemoSeeder extends Seeder
{
    public function run(): void
    {
        $product = Product::query()->firstOrCreate(
            ['name' => 'Demo Widget'],
            ['is_active' => true],
        );

        $standardVariant = ProductVariant::query()->firstOrCreate(
            ['sku' => 'DEMO-WIDGET-STD'],
            ['product_id' => $product->id, 'name' => 'Demo Widget Standard', 'is_active' => true],
        );
        $lowStockVariant = ProductVariant::query()->firstOrCreate(
            ['sku' => 'DEMO-WIDGET-LOW'],
            ['product_id' => $product->id, 'name' => 'Demo Widget Low Stock', 'is_active' => true],
        );

        $centralWarehouse = Warehouse::query()->firstOrCreate(
            ['code' => 'DEMO-CENTRAL'],
            ['name' => 'Demo Central Warehouse', 'is_active' => true],
        );
        $westWarehouse = Warehouse::query()->firstOrCreate(
            ['code' => 'DEMO-WEST'],
            ['name' => 'Demo West Warehouse', 'is_active' => true],
        );

        WarehouseLocation::query()->firstOrCreate(
            ['warehouse_id' => $centralWarehouse->id, 'code' => 'A-01'],
            ['name' => 'Central Aisle 01', 'is_active' => true],
        );
        WarehouseLocation::query()->firstOrCreate(
            ['warehouse_id' => $westWarehouse->id, 'code' => 'B-01'],
            ['name' => 'West Aisle 01', 'is_active' => true],
        );

        $this->seedStock($standardVariant, $centralWarehouse, [
            'on_hand_quantity' => '15.000',
            'reserved_quantity' => '1.000',
            'available_quantity' => '14.000',
            'reorder_level' => '5.000',
        ]);
        $this->seedStock($lowStockVariant, $centralWarehouse, [
            'on_hand_quantity' => '5.000',
            'reserved_quantity' => '1.000',
            'available_quantity' => '4.000',
            'reorder_level' => '5.000',
        ]);
        $this->seedStock($standardVariant, $westWarehouse, [
            'on_hand_quantity' => '8.000',
            'reserved_quantity' => '0.000',
            'available_quantity' => '8.000',
            'reorder_level' => null,
        ]);

        $this->seedMovement($standardVariant, $centralWarehouse, [
            'movement_type' => MovementType::Sale,
            'quantity' => '-2.000',
            'source_type' => 'delivery_note',
            'source_id' => 1001,
        ]);
        $this->seedMovement($standardVariant, $centralWarehouse, [
            'movement_type' => MovementType::Return,
            'quantity' => '2.000',
            'source_type' => 'credit_note',
            'source_id' => 1002,
        ]);
        $this->seedMovement($lowStockVariant, $westWarehouse, [
            'movement_type' => MovementType::Transfer,
            'quantity' => '-5.000',
            'source_type' => 'transfer',
            'source_id' => 1003,
        ]);

        $this->seedDraftAdjustment($centralWarehouse, $standardVariant, $lowStockVariant);
        $this->seedDraftTransfer($centralWarehouse, $westWarehouse, $standardVariant);
    }

    /**
     * A sample **draft** adjustment for manual smoke testing (FI-3). Left
     * unconfirmed on purpose — confirming is the one action that changes
     * stock, and a demo seeder must not do that behind the scenes.
     */
    private function seedDraftAdjustment(Warehouse $warehouse, ProductVariant $variantOne, ProductVariant $variantTwo): void
    {
        $adjustment = InventoryAdjustment::query()->firstOrCreate(
            ['warehouse_id' => $warehouse->id, 'reason' => 'Demo physical count discrepancy'],
        );

        $adjustment->items()->firstOrCreate(
            ['product_variant_id' => $variantOne->id],
            ['new_quantity' => '18.000'],
        );
        $adjustment->items()->firstOrCreate(
            ['product_variant_id' => $variantTwo->id],
            ['new_quantity' => '3.000'],
        );
    }

    /**
     * A sample **draft** transfer for manual smoke testing (FI-4), moving
     * stock the central warehouse actually has available. Left unconfirmed
     * on purpose — confirming is the one action that changes stock, and a
     * demo seeder must not do that behind the scenes.
     */
    private function seedDraftTransfer(Warehouse $from, Warehouse $to, ProductVariant $variant): void
    {
        $transfer = StockTransfer::query()->firstOrCreate(
            ['from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id, 'notes' => 'Demo restock of west branch'],
        );

        $transfer->items()->firstOrCreate(
            ['product_variant_id' => $variant->id],
            ['quantity' => '5.000'],
        );
    }

    /**
     * @param  array{
     *     on_hand_quantity: string,
     *     reserved_quantity: string,
     *     available_quantity: string,
     *     reorder_level: string|null,
     * }  $stockValues
     */
    private function seedStock(
        ProductVariant $productVariant,
        Warehouse $warehouse,
        array $stockValues,
    ): void {
        $stock = InventoryStock::query()
            ->where('product_variant_id', $productVariant->id)
            ->where('warehouse_id', $warehouse->id)
            ->first() ?? new InventoryStock;

        $stock->forceFill([
            'product_variant_id' => $productVariant->id,
            'warehouse_id' => $warehouse->id,
            ...$stockValues,
        ])->save();
    }

    /**
     * @param  array{
     *     movement_type: MovementType,
     *     quantity: string,
     *     source_type: string,
     *     source_id: int,
     * }  $movementValues
     */
    private function seedMovement(
        ProductVariant $productVariant,
        Warehouse $warehouse,
        array $movementValues,
    ): void {
        $movement = InventoryMovement::query()
            ->where('product_variant_id', $productVariant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('movement_type', $movementValues['movement_type']->value)
            ->where('source_type', $movementValues['source_type'])
            ->where('source_id', $movementValues['source_id'])
            ->first() ?? new InventoryMovement;

        $movement->forceFill([
            'product_variant_id' => $productVariant->id,
            'warehouse_id' => $warehouse->id,
            ...$movementValues,
            'status' => 'confirmed',
        ])->save();
    }
}
