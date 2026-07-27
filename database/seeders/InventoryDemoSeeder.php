<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ReservationStatus;
use App\Enums\UserType;
use App\Models\InventoryAdjustment;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\StockReservation;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Inventory\InventoryAlertService;
use App\Services\Inventory\InventoryReceivingService;
use App\Services\Inventory\StockTransferService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class InventoryDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DentalCatalogSeeder::class);

        /** @var array<string, ProductVariant> $variants keyed by SKU */
        $variants = ProductVariant::query()->get()->keyBy('sku')->all();

        $this->seedWarehouseOperations($variants);
    }

    /**
     * Gives every warehouse-side screen (stock, movements, lots, devices,
     * transfers, adjustments, returns, reservations, alerts) at least one
     * believable record, so a client walkthrough never lands on an empty
     * state. Runs once: a confirmed receipt at the main warehouse is used as
     * the idempotency marker, since documents (unlike catalogue rows) have no
     * natural unique key to `updateOrCreate` against.
     *
     * @param  array<string, ProductVariant>  $variants  keyed by SKU
     */
    private function seedWarehouseOperations(array $variants): void
    {
        $warehouses = $this->seedWarehouses();
        $locations = $this->seedWarehouseLocations($warehouses);

        if (InventoryReceipt::query()->where('warehouse_id', $warehouses['MAIN']->getKey())->exists()) {
            return;
        }

        $actor = $this->demoActor();

        $this->seedMainReceipt($warehouses['MAIN'], $locations, $variants, $actor);
        $this->seedColdReceipt($warehouses['COLD'], $locations, $variants, $actor);
        $this->seedTransfers($warehouses, $locations, $variants, $actor);
        $this->seedAdjustments($warehouses, $variants, $actor);
        $this->seedReturnAndReservation($warehouses['MAIN'], $variants, $actor);
        $this->seedLowStockAlert($warehouses['MAIN'], $variants['FORMLABS-FORM-4B']);
    }

    /** @return array<string, Warehouse> */
    private function seedWarehouses(): array
    {
        $definitions = [
            'MAIN' => ['name' => 'Main Clinic Store', 'address' => '1 Clinic Row, Suite 100'],
            'COLD' => ['name' => 'Cold Chain Storage', 'address' => '2 Clinic Row, Suite 200'],
            'BENCH' => ['name' => 'Repair Bench', 'address' => '3 Clinic Row, Suite 300'],
        ];

        $warehouses = [];

        foreach ($definitions as $code => $definition) {
            $warehouses[$code] = Warehouse::query()->updateOrCreate(
                ['code' => $code],
                [...$definition, 'is_active' => true],
            );
        }

        return $warehouses;
    }

    /**
     * @param  array<string, Warehouse>  $warehouses
     * @return array<string, WarehouseLocation> keyed by "{warehouseCode}-{locationCode}"
     */
    private function seedWarehouseLocations(array $warehouses): array
    {
        $definitions = [
            'MAIN' => ['A-01' => 'Aisle A, Shelf 1', 'A-02' => 'Aisle A, Shelf 2'],
            'COLD' => ['C-01' => 'Fridge 1'],
            'BENCH' => ['B-01' => 'Workbench 1'],
        ];

        $locations = [];

        foreach ($definitions as $warehouseCode => $codes) {
            foreach ($codes as $locationCode => $name) {
                $locations[$warehouseCode.'-'.$locationCode] = WarehouseLocation::query()->updateOrCreate(
                    ['warehouse_id' => $warehouses[$warehouseCode]->getKey(), 'code' => $locationCode],
                    ['name' => $name, 'is_active' => true],
                );
            }
        }

        return $locations;
    }

    private function demoActor(): User
    {
        $admin = User::query()->where('email', 'admin@ierp.com')->first();

        if ($admin instanceof User) {
            return $admin;
        }

        return User::query()->firstOrCreate(
            ['user_type' => UserType::Admin],
            ['name' => 'Admin User', 'email' => 'admin@ierp.com', 'password' => Hash::make('password')],
        );
    }

    /**
     * @param  array<string, WarehouseLocation>  $locations
     * @param  array<string, ProductVariant>  $variants
     */
    private function seedMainReceipt(Warehouse $main, array $locations, array $variants, User $actor): void
    {
        $receipt = InventoryReceipt::query()->create(['warehouse_id' => $main->getKey()]);

        $printer = $receipt->items()->create([
            'product_variant_id' => $variants['FORMLABS-FORM-4B']->getKey(),
            'quantity' => 2,
            'purchase_cost' => 3200,
            'warehouse_location_id' => $locations['MAIN-A-01']->getKey(),
        ]);
        SerializedInventoryUnit::query()->create(['product_variant_id' => $printer->product_variant_id, 'inventory_receipt_item_id' => $printer->getKey(), 'serial_number' => 'FORM4B-DEMO-0001']);
        SerializedInventoryUnit::query()->create(['product_variant_id' => $printer->product_variant_id, 'inventory_receipt_item_id' => $printer->getKey(), 'serial_number' => 'FORM4B-DEMO-0002']);

        $receipt->items()->create([
            'product_variant_id' => $variants['FORMLABS-PRECISION-MODEL-1L']->getKey(),
            'quantity' => 5,
            'purchase_cost' => 85,
            'lot_number' => 'LOT-PRECISION-01',
            'expires_at' => now()->addDays(10),
            'warehouse_location_id' => $locations['MAIN-A-02']->getKey(),
        ]);

        $washer = $receipt->items()->create([
            'product_variant_id' => $variants['FORMLABS-FORM-WASH-V2']->getKey(),
            'quantity' => 1,
            'purchase_cost' => 950,
            'warehouse_location_id' => $locations['MAIN-A-01']->getKey(),
        ]);
        SerializedInventoryUnit::query()->create(['product_variant_id' => $washer->product_variant_id, 'inventory_receipt_item_id' => $washer->getKey(), 'serial_number' => 'WASHV2-DEMO-0001']);

        app(InventoryReceivingService::class)->confirm($receipt, $actor);
    }

    /**
     * @param  array<string, WarehouseLocation>  $locations
     * @param  array<string, ProductVariant>  $variants
     */
    private function seedColdReceipt(Warehouse $cold, array $locations, array $variants, User $actor): void
    {
        $receipt = InventoryReceipt::query()->create(['warehouse_id' => $cold->getKey()]);

        $receipt->items()->create([
            'product_variant_id' => $variants['FORMLABS-SURGICAL-GUIDE-1L']->getKey(),
            'quantity' => 4,
            'purchase_cost' => 95,
            'lot_number' => 'LOT-SURGICAL-01',
            'expires_at' => now()->addDays(200),
            'warehouse_location_id' => $locations['COLD-C-01']->getKey(),
        ]);

        app(InventoryReceivingService::class)->confirm($receipt, $actor);
    }

    /**
     * @param  array<string, Warehouse>  $warehouses
     * @param  array<string, WarehouseLocation>  $locations
     * @param  array<string, ProductVariant>  $variants
     */
    private function seedTransfers(array $warehouses, array $locations, array $variants, User $actor): void
    {
        $washUnit = SerializedInventoryUnit::query()
            ->where('product_variant_id', $variants['FORMLABS-FORM-WASH-V2']->getKey())
            ->where('warehouse_id', $warehouses['MAIN']->getKey())
            ->firstOrFail();

        $transferService = app(StockTransferService::class);

        $completed = StockTransfer::query()->create([
            'from_warehouse_id' => $warehouses['MAIN']->getKey(),
            'to_warehouse_id' => $warehouses['BENCH']->getKey(),
            'notes' => 'Sent for scheduled maintenance.',
        ]);
        $completed->items()->create([
            'product_variant_id' => $variants['FORMLABS-FORM-WASH-V2']->getKey(),
            'serialized_inventory_unit_id' => $washUnit->getKey(),
            'quantity' => 1,
            'warehouse_location_id' => $locations['BENCH-B-01']->getKey(),
        ]);
        $transferService->dispatch($completed, $actor);
        $transferService->receive($completed, $actor);

        StockTransfer::query()->create([
            'from_warehouse_id' => $warehouses['MAIN']->getKey(),
            'to_warehouse_id' => $warehouses['COLD']->getKey(),
            'notes' => 'Restocking cold storage — pending dispatch.',
        ])->items()->create([
            'product_variant_id' => $variants['FORMLABS-PRECISION-MODEL-1L']->getKey(),
            'quantity' => 1,
        ]);
    }

    /**
     * @param  array<string, Warehouse>  $warehouses
     * @param  array<string, ProductVariant>  $variants
     */
    private function seedAdjustments(array $warehouses, array $variants, User $actor): void
    {
        $resinVariant = $variants['FORMLABS-PRECISION-MODEL-1L'];
        $rawOnHand = InventoryStock::query()
            ->where('product_variant_id', $resinVariant->getKey())
            ->where('warehouse_id', $warehouses['MAIN']->getKey())
            ->value('on_hand_quantity');
        $onHand = is_numeric($rawOnHand) ? (float) $rawOnHand : 0.0;

        $completed = InventoryAdjustment::query()->create([
            'warehouse_id' => $warehouses['MAIN']->getKey(),
            'reason' => 'Cycle count correction: two additional bottles found in storage.',
        ]);
        $completed->items()->create([
            'product_variant_id' => $resinVariant->getKey(),
            'new_quantity' => $onHand + 2,
        ]);
        app(InventoryAdjustmentService::class)->confirm($completed, $actor);

        InventoryAdjustment::query()->create([
            'warehouse_id' => $warehouses['COLD']->getKey(),
            'reason' => 'Pending recount after delivery discrepancy.',
        ])->items()->create([
            'product_variant_id' => $variants['FORMLABS-SURGICAL-GUIDE-1L']->getKey(),
            'new_quantity' => 5,
        ]);
    }

    /** @param array<string, ProductVariant> $variants */
    private function seedReturnAndReservation(Warehouse $main, array $variants, User $actor): void
    {
        InventoryMovement::factory()->return()->create([
            'product_variant_id' => $variants['FORMLABS-FORM-4B']->getKey(),
            'warehouse_id' => $main->getKey(),
            'quantity' => 1,
            'source_type' => 'return',
            'source_id' => 1,
            'created_by' => $actor->getKey(),
            'notes' => 'Customer returned an unopened unit within the trial period.',
        ]);

        StockReservation::query()->create([
            'product_variant_id' => $variants['FORMLABS-PRECISION-MODEL-1L']->getKey(),
            'warehouse_id' => $main->getKey(),
            'quantity' => 1,
            'source_type' => 'manual',
            'source_id' => 1,
            'status' => ReservationStatus::Active,
        ]);
    }

    private function seedLowStockAlert(Warehouse $main, ProductVariant $printerVariant): void
    {
        $stock = InventoryStock::query()
            ->where('product_variant_id', $printerVariant->getKey())
            ->where('warehouse_id', $main->getKey())
            ->firstOrFail();

        $stock->forceFill(['reorder_level' => 5])->save();

        app(InventoryAlertService::class)->syncStock($stock);
    }
}
