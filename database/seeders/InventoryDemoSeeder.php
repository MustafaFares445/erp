<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Data\Inventory\PriceFloorOverrideData;
use App\Data\Inventory\PricingTierData;
use App\Data\Inventory\VariantPricingData;
use App\Enums\OperationType;
use App\Enums\ReservationStatus;
use App\Enums\UserType;
use App\Models\InventoryAdjustment;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryReceipt;
use App\Models\InventoryStock;
use App\Models\Package;
use App\Models\PackageType;
use App\Models\PricingTier;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\StockReservation;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Inventory\InventoryAlertService;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\InventoryReceivingService;
use App\Services\Inventory\ProductPricingService;
use App\Services\Inventory\StockTransferService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use LogicException;

final class InventoryDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PackageTypeSeeder::class);
        $this->call(DentalCatalogSeeder::class);

        /** @var array<string, ProductVariant> $variants keyed by SKU */
        $variants = ProductVariant::query()->get()->keyBy('sku')->all();
        $suppliers = $this->seedPurchasingData($variants);

        $this->seedWarehouseOperations($variants, $suppliers);
        $this->seedInventoryOperationWorkflow($variants, $suppliers);
        $this->seedPricingDemo($variants);
    }

    /**
     * @param  array<string, ProductVariant>  $variants
     * @return array<string, Supplier> keyed by supplier code
     */
    private function seedPurchasingData(array $variants): array
    {
        $definitions = [
            'FORMLABS-US' => [
                'name' => 'Formlabs Dental EMEA',
                'email' => 'orders-emea@formlabs.example',
                'phone' => '+49 30 5550 4100',
                'address' => 'Berlin Distribution Hub, Germany',
            ],
            'DENTSPLY-MENA' => [
                'name' => 'Dentsply Sirona Middle East',
                'email' => 'supply@dentsply.example',
                'phone' => '+971 4 555 2277',
                'address' => 'Dubai Healthcare City, United Arab Emirates',
            ],
            'IVOCLAR-LEVANT' => [
                'name' => 'Ivoclar Levant',
                'email' => 'purchasing@ivoclar.example',
                'phone' => '+961 1 555 800',
                'address' => 'Beirut Medical District, Lebanon',
            ],
        ];

        $suppliers = [];

        foreach ($definitions as $code => $definition) {
            $suppliers[$code] = Supplier::query()->updateOrCreate(
                ['code' => $code],
                [...$definition, 'is_active' => true],
            );
        }

        $references = [
            ['supplier' => 'FORMLABS-US', 'sku' => 'FORMLABS-FORM-4B', 'item' => 'F4B-DENTAL-EU', 'cost' => 3200.00],
            ['supplier' => 'FORMLABS-US', 'sku' => 'FORMLABS-PRECISION-MODEL-1L', 'item' => 'RS-F4-PM-1L', 'cost' => 60.00],
            ['supplier' => 'FORMLABS-US', 'sku' => 'FORMLABS-SURGICAL-GUIDE-1L', 'item' => 'RS-F4-SG-1L', 'cost' => 95.00],
            ['supplier' => 'FORMLABS-US', 'sku' => 'FORMLABS-FORM-WASH-V2', 'item' => 'FWV2-EU', 'cost' => 950.00],
            ['supplier' => 'DENTSPLY-MENA', 'sku' => 'DENTSPLY-PRIMEPRINT-SOLUTION', 'item' => 'PPS-MENA', 'cost' => 12800.00],
            ['supplier' => 'DENTSPLY-MENA', 'sku' => 'DENTSPLY-PRIMEPRINT-PPU', 'item' => 'PPU-MENA', 'cost' => 4900.00],
            ['supplier' => 'IVOCLAR-LEVANT', 'sku' => 'IVOCLAR-PROGRAPRINT-PR5', 'item' => 'PR5-LEV', 'cost' => 9800.00],
        ];

        foreach ($references as $reference) {
            $supplier = $suppliers[$reference['supplier']];
            $variant = $variants[$reference['sku']];

            SupplierProductReference::query()->updateOrCreate(
                [
                    'supplier_id' => $supplier->getKey(),
                    'product_variant_id' => $variant->getKey(),
                ],
                [
                    'supplier_name' => $supplier->name,
                    'supplier_item_number' => $reference['item'],
                    'country_code' => match ($reference['supplier']) {
                        'FORMLABS-US' => 'DE',
                        'DENTSPLY-MENA' => 'AE',
                        'IVOCLAR-LEVANT' => 'LB',
                    },
                    'manufacturer' => $this->manufacturerName($variant),
                    'purchase_cost' => $reference['cost'],
                    'currency_code' => 'USD',
                    'notes' => 'Approved purchasing reference for the inventory demo.',
                    'is_active' => true,
                ],
            );
        }

        return $suppliers;
    }

    private function manufacturerName(ProductVariant $variant): string
    {
        $product = $variant->product;
        $brand = $product?->brand;

        if ($brand === null) {
            throw new LogicException(sprintf('The product variant [%s] must belong to a branded product.', $variant->sku));
        }

        return $brand->name;
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
     * @param  array<string, Supplier>  $suppliers  keyed by supplier code
     */
    private function seedWarehouseOperations(array $variants, array $suppliers): void
    {
        $warehouses = $this->seedWarehouses();
        $this->seedPackages($warehouses);

        if (InventoryReceipt::query()->where('warehouse_id', $warehouses['MAIN']->getKey())->exists()) {
            return;
        }

        $actor = $this->demoActor();

        $this->seedMainReceipt($warehouses['MAIN'], $variants, $suppliers['FORMLABS-US'], $actor);
        $this->seedColdReceipt($warehouses['COLD'], $variants, $suppliers['FORMLABS-US'], $actor);
        $this->seedTransfers($warehouses, $variants, $actor);
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

    private function demoActor(): User
    {
        $admin = User::query()->where('email', 'admin@ierp.com')->first();

        if (! $admin instanceof User) {
            $admin = User::query()->firstOrCreate(
                ['user_type' => UserType::Admin],
                ['name' => 'Admin User', 'email' => 'admin@ierp.com', 'password' => Hash::make('password')],
            );
        }

        $this->call(InventoryPermissionSeeder::class);

        return $admin->refresh();
    }

    /**
     * @param  array<string, ProductVariant>  $variants
     */
    private function seedMainReceipt(Warehouse $main, array $variants, Supplier $supplier, User $actor): void
    {
        $receipt = InventoryReceipt::query()->create([
            'warehouse_id' => $main->getKey(),
            'supplier_id' => $supplier->getKey(),
            'supplier_reference' => 'FL-INV-2026-1001',
            'notes' => 'Initial Formlabs equipment and resin replenishment.',
        ]);

        $printer = $receipt->items()->create([
            'product_variant_id' => $variants['FORMLABS-FORM-4B']->getKey(),
            'quantity' => 2,
            'purchase_cost' => 3200,
        ]);
        SerializedInventoryUnit::query()->create(['product_variant_id' => $printer->product_variant_id, 'inventory_receipt_item_id' => $printer->getKey(), 'serial_number' => 'FORM4B-DEMO-0001']);
        SerializedInventoryUnit::query()->create(['product_variant_id' => $printer->product_variant_id, 'inventory_receipt_item_id' => $printer->getKey(), 'serial_number' => 'FORM4B-DEMO-0002']);

        $receipt->items()->create([
            'product_variant_id' => $variants['FORMLABS-PRECISION-MODEL-1L']->getKey(),
            'quantity' => 5,
            'purchase_cost' => 85,
            'lot_number' => 'LOT-PRECISION-01',
            'expires_at' => now()->addDays(10),
        ]);

        $washer = $receipt->items()->create([
            'product_variant_id' => $variants['FORMLABS-FORM-WASH-V2']->getKey(),
            'quantity' => 1,
            'purchase_cost' => 950,
        ]);
        SerializedInventoryUnit::query()->create(['product_variant_id' => $washer->product_variant_id, 'inventory_receipt_item_id' => $washer->getKey(), 'serial_number' => 'WASHV2-DEMO-0001']);

        app(InventoryReceivingService::class)->confirm($receipt, $actor);
    }

    /**
     * @param  array<string, ProductVariant>  $variants
     */
    private function seedColdReceipt(Warehouse $cold, array $variants, Supplier $supplier, User $actor): void
    {
        $receipt = InventoryReceipt::query()->create([
            'warehouse_id' => $cold->getKey(),
            'supplier_id' => $supplier->getKey(),
            'supplier_reference' => 'FL-INV-2026-1014',
            'notes' => 'Cold-chain surgical resin replenishment.',
        ]);

        $receipt->items()->create([
            'product_variant_id' => $variants['FORMLABS-SURGICAL-GUIDE-1L']->getKey(),
            'quantity' => 4,
            'purchase_cost' => 95,
            'lot_number' => 'LOT-SURGICAL-01',
            'expires_at' => now()->addDays(200),
        ]);

        app(InventoryReceivingService::class)->confirm($receipt, $actor);
    }

    /**
     * @param  array<string, Warehouse>  $warehouses
     * @param  array<string, ProductVariant>  $variants
     */
    private function seedTransfers(array $warehouses, array $variants, User $actor): void
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

    /**
     * Seeds the operational screens with purchasing, delivery, transfer, and exception states.
     * Each confirmed transition uses the same service used by Filament, keeping balances,
     * reservations, movements, and audit records internally consistent.
     *
     * @param  array<string, ProductVariant>  $variants  keyed by SKU
     * @param  array<string, Supplier>  $suppliers  keyed by supplier code
     */
    private function seedInventoryOperationWorkflow(array $variants, array $suppliers): void
    {
        if (InventoryOperation::query()->where('notes', 'Demo workflow: delivered Formlabs replenishment.')->exists()) {
            return;
        }

        $actor = $this->demoActor();
        $main = $this->warehouseByCode('MAIN');
        $cold = $this->warehouseByCode('COLD');
        $bench = $this->warehouseByCode('BENCH');
        $mainResinPackage = $this->packageByName('Main resin carton');
        $coldResinPackage = $this->packageByName('Cold-chain resin carton');
        $service = app(InventoryOperationService::class);

        $completedReceipt = InventoryOperation::query()->create([
            'operation_type' => OperationType::Receipt,
            'destination_warehouse_id' => $main->getKey(),
            'supplier_id' => $suppliers['FORMLABS-US']->getKey(),
            'supplier_reference' => 'PO-FL-2026-1021',
            'scheduled_at' => now()->subDays(2),
            'responsible_id' => $actor->getKey(),
            'notes' => 'Demo workflow: delivered Formlabs replenishment.',
        ]);
        $completedReceipt->lines()->create([
            'product_variant_id' => $variants['FORMLABS-PRECISION-MODEL-1L']->getKey(),
            'quantity' => 12,
            'unit_id' => $variants['FORMLABS-PRECISION-MODEL-1L']->unit_id,
            'unit_cost' => 60,
            'package_id' => $mainResinPackage->getKey(),
        ]);
        $service->markReady($completedReceipt);
        $service->complete($completedReceipt->refresh(), $actor);

        $delivery = InventoryOperation::query()->create([
            'operation_type' => OperationType::Delivery,
            'source_warehouse_id' => $main->getKey(),
            'source_document_type' => 'sales_order',
            'source_document_id' => 2026001,
            'scheduled_at' => now()->addDay(),
            'responsible_id' => $actor->getKey(),
            'notes' => 'Demo workflow: reserved resin for Smile Dental Clinic.',
        ]);
        $delivery->lines()->create([
            'product_variant_id' => $variants['FORMLABS-PRECISION-MODEL-1L']->getKey(),
            'quantity' => 3,
            'unit_id' => $variants['FORMLABS-PRECISION-MODEL-1L']->unit_id,
            'unit_cost' => 84,
            'package_id' => $mainResinPackage->getKey(),
        ]);
        $service->markReady($delivery);

        $inTransitTransfer = InventoryOperation::query()->create([
            'operation_type' => OperationType::InternalTransfer,
            'source_warehouse_id' => $cold->getKey(),
            'destination_warehouse_id' => $main->getKey(),
            'scheduled_at' => now(),
            'responsible_id' => $actor->getKey(),
            'notes' => 'Demo workflow: cold-chain stock transfer awaiting receipt.',
        ]);
        $inTransitTransfer->lines()->create([
            'product_variant_id' => $variants['FORMLABS-SURGICAL-GUIDE-1L']->getKey(),
            'quantity' => 1,
            'unit_id' => $variants['FORMLABS-SURGICAL-GUIDE-1L']->unit_id,
            'unit_cost' => 95,
            'package_id' => $coldResinPackage->getKey(),
        ]);
        $service->markReady($inTransitTransfer);
        $service->dispatch($inTransitTransfer->refresh(), $actor);

        $draftReceipt = InventoryOperation::query()->create([
            'operation_type' => OperationType::Receipt,
            'destination_warehouse_id' => $main->getKey(),
            'supplier_id' => $suppliers['DENTSPLY-MENA']->getKey(),
            'supplier_reference' => 'PO-DS-2026-1104',
            'scheduled_at' => now()->addWeeks(2),
            'responsible_id' => $actor->getKey(),
            'notes' => 'Demo workflow: draft Dentsply purchase order pending approval.',
        ]);
        $draftReceipt->lines()->create([
            'product_variant_id' => $variants['DENTSPLY-PRIMEPRINT-PPU']->getKey(),
            'quantity' => 1,
            'unit_id' => $variants['DENTSPLY-PRIMEPRINT-PPU']->unit_id,
            'unit_cost' => 4900,
        ]);

        $waitingDelivery = InventoryOperation::query()->create([
            'operation_type' => OperationType::Delivery,
            'source_warehouse_id' => $bench->getKey(),
            'source_document_type' => 'service_order',
            'source_document_id' => 2026002,
            'scheduled_at' => now()->addDays(3),
            'responsible_id' => $actor->getKey(),
            'notes' => 'Demo workflow: waiting for unavailable Primeprint PPU stock.',
        ]);
        $waitingDelivery->lines()->create([
            'product_variant_id' => $variants['DENTSPLY-PRIMEPRINT-PPU']->getKey(),
            'quantity' => 1,
            'unit_id' => $variants['DENTSPLY-PRIMEPRINT-PPU']->unit_id,
            'unit_cost' => 4900,
        ]);
        $service->markReady($waitingDelivery);
    }

    /**
     * @param  array<string, Warehouse>  $warehouses
     */
    private function seedPackages(array $warehouses): void
    {
        $types = PackageType::query()->get()->keyBy('code');
        $definitions = [
            ['name' => 'Main resin carton', 'type' => 'BOX', 'warehouse' => 'MAIN'],
            ['name' => 'Cold-chain resin carton', 'type' => 'BOX', 'warehouse' => 'COLD'],
            ['name' => 'Maintenance wash bottle', 'type' => 'BOTTLE', 'warehouse' => 'BENCH'],
        ];

        foreach ($definitions as $definition) {
            $type = $types->get($definition['type']);

            if (! $type instanceof PackageType) {
                throw new LogicException(sprintf('Package type [%s] must be seeded first.', $definition['type']));
            }

            Package::query()->updateOrCreate(
                ['name' => $definition['name']],
                [
                    'package_type_id' => $type->getKey(),
                    'warehouse_id' => $warehouses[$definition['warehouse']]->getKey(),
                    'is_active' => true,
                ],
            );
        }
    }

    private function packageByName(string $name): Package
    {
        /** @var Package $package */
        $package = Package::query()->where('name', $name)->firstOrFail();

        return $package;
    }

    private function warehouseByCode(string $code): Warehouse
    {
        /** @var Warehouse $warehouse */
        $warehouse = Warehouse::query()->where('code', $code)->firstOrFail();

        return $warehouse;
    }

    /**
     * Gives the pricing screens (tiers, customer assignments, price history,
     * floor overrides) at least one believable record. Runs once: a pricing
     * tier is used as the idempotency marker, since pricing rows have no
     * natural unique key to `updateOrCreate` against.
     *
     * @param  array<string, ProductVariant>  $variants  keyed by SKU
     */
    private function seedPricingDemo(array $variants): void
    {
        if (PricingTier::query()->exists()) {
            return;
        }

        $actor = $this->demoActor();
        $pricingService = app(ProductPricingService::class);
        $customers = $this->seedDemoCustomers();
        $smileCustomerId = $this->modelId($customers['smile']);

        $loyaltyTier = $pricingService->saveTier(null, new PricingTierData(
            name: 'Loyalty Clinics',
            discountPercent: 10.0,
            customerUserId: null,
            isActive: true,
        ), $actor);

        $pricingService->saveTier(null, new PricingTierData(
            name: 'Smile Dental Clinic — VIP',
            discountPercent: 15.0,
            customerUserId: $smileCustomerId,
            isActive: true,
        ), $actor);

        $pricingService->assignGeneralTier($customers['bright'], $loyaltyTier, $actor);

        $resinVariant = $variants['FORMLABS-PRECISION-MODEL-1L'];
        $pricingService->updateVariantPricing($resinVariant, new VariantPricingData(
            costPrice: 60.0,
            markupPercent: 40.0,
            minimumPrice: 70.0,
        ), $actor);

        $pricingService->approveFloorOverride(new PriceFloorOverrideData(
            productVariantId: $this->modelId($resinVariant),
            customerUserId: $smileCustomerId,
            attemptedPrice: 65.0,
            reason: 'One-off approval for a bulk clinic order below the configured floor price.',
        ), $actor);
    }

    private function modelId(User|ProductVariant $model): int
    {
        $id = $model->getKey();

        if (! is_int($id)) {
            throw new LogicException('A persisted model with an integer key is required.');
        }

        return $id;
    }

    /** @return array<string, User> keyed by a short mnemonic */
    private function seedDemoCustomers(): array
    {
        return [
            'smile' => User::factory()->customer()->create([
                'name' => 'Smile Dental Clinic',
                'email' => 'smile-dental-clinic@ierp.com',
            ]),
            'bright' => User::factory()->customer()->create([
                'name' => 'Bright Orthodontics',
                'email' => 'bright-orthodontics@ierp.com',
            ]),
        ];
    }
}
