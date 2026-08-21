<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Data\Inventory\PriceFloorOverrideData;
use App\Data\Inventory\PricingTierData;
use App\Data\Inventory\VariantPricingData;
use App\Enums\DeliveryDocument;
use App\Enums\DeliveryType;
use App\Enums\OperationType;
use App\Enums\PricingTierDiscountType;
use App\Enums\PricingTierType;
use App\Enums\ReservationStatus;
use App\Enums\UserType;
use App\Models\CustomerProfile;
use App\Models\InventoryAdjustment;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryReceipt;
use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackageType;
use App\Models\PricingTier;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Shipment;
use App\Models\StockReservation;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Inventory\InventoryAlertService;
use App\Services\Inventory\InventoryLotService;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\InventoryReceivingService;
use App\Services\Inventory\PricingTierService;
use App\Services\Inventory\ProductPricingService;
use App\Services\Inventory\StockTransferService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class InventoryDemoSeeder extends Seeder
{
    private const string TestingPlaceholderPdf = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF";

    /**
     * Extra receipts that put every catalogue variant into a realistic warehouse context.
     *
     * @var list<array{
     *     supplier: string,
     *     warehouse: string,
     *     reference: string,
     *     notes: string,
     *     items: list<array{
     *         sku: string,
     *         quantity: float|int,
     *         cost: float|int,
     *         serial_numbers?: list<string>,
     *         lot_number?: string,
     *         expires_in_days?: int
     *     }>
     * }>
     */
    private const array WarehouseCoverageReceipts = [
        [
            'supplier' => 'FORMLABS-US',
            'warehouse' => 'MAIN',
            'reference' => 'FL-DEMO-COVERAGE-2026-2001',
            'notes' => 'Formlabs alternate equipment and larger resin pack replenishment.',
            'items' => [
                ['sku' => 'FORMLABS-FORM-4B-120V', 'quantity' => 1, 'cost' => 3200.00, 'serial_numbers' => ['FORM4B-120V-DEMO-0001']],
                ['sku' => 'FORMLABS-FORM-4B-PREMIUM-230V', 'quantity' => 1, 'cost' => 3950.00, 'serial_numbers' => ['FORM4B-PREMIUM-DEMO-0001']],
                ['sku' => 'FORMLABS-PRECISION-MODEL-5L', 'quantity' => 8, 'cost' => 285.00, 'lot_number' => 'LOT-PRECISION-5L-01', 'expires_in_days' => 420],
            ],
        ],
        [
            'supplier' => 'DENTSPLY-MENA',
            'warehouse' => 'MAIN',
            'reference' => 'DS-DEMO-COVERAGE-2026-2002',
            'notes' => 'Dentsply equipment and bulk dental stone received into central stock.',
            'items' => [
                ['sku' => 'DENTSPLY-PRIMEPRINT-SOLUTION', 'quantity' => 1, 'cost' => 12800.00, 'serial_numbers' => ['PRIMEPRINT-SOLUTION-DEMO-0001']],
                ['sku' => 'DENTSPLY-PRIMEPRINT-SOLUTION-110V', 'quantity' => 1, 'cost' => 12800.00, 'serial_numbers' => ['PRIMEPRINT-SOLUTION-110V-DEMO-0001']],
                ['sku' => 'DENTSPLY-PRIMEPRINT-PPU', 'quantity' => 1, 'cost' => 4900.00, 'serial_numbers' => ['PRIMEPRINT-PPU-DEMO-0002']],
                ['sku' => 'DENTSPLY-PRIMEPRINT-PPU-230V', 'quantity' => 1, 'cost' => 4900.00, 'serial_numbers' => ['PRIMEPRINT-PPU-230V-DEMO-0001']],
                ['sku' => 'DENTSPLY-DENTAL-STONE-25KG', 'quantity' => 42.5, 'cost' => 22.00],
            ],
        ],
        [
            'supplier' => 'IVOCLAR-LEVANT',
            'warehouse' => 'MAIN',
            'reference' => 'IV-DEMO-COVERAGE-2026-2003',
            'notes' => 'Ivoclar printer configurations received for demonstration and sale.',
            'items' => [
                ['sku' => 'IVOCLAR-PROGRAPRINT-PR5', 'quantity' => 1, 'cost' => 9800.00, 'serial_numbers' => ['PR5-DEMO-0001']],
                ['sku' => 'IVOCLAR-PROGRAPRINT-PR5-100-240V', 'quantity' => 1, 'cost' => 9800.00, 'serial_numbers' => ['PR5-UNIVERSAL-DEMO-0001']],
            ],
        ],
        [
            'supplier' => 'FORMLABS-US',
            'warehouse' => 'COLD',
            'reference' => 'FL-DEMO-COVERAGE-2026-2004',
            'notes' => 'Long-dated surgical resin refill kept in cold-chain storage.',
            'items' => [
                ['sku' => 'FORMLABS-SURGICAL-GUIDE-5L', 'quantity' => 6, 'cost' => 450.00, 'lot_number' => 'LOT-SURGICAL-5L-01', 'expires_in_days' => 540],
            ],
        ],
        [
            'supplier' => 'FORMLABS-US',
            'warehouse' => 'BENCH',
            'reference' => 'FL-DEMO-COVERAGE-2026-2005',
            'notes' => '120V wash station assigned to the repair bench.',
            'items' => [
                ['sku' => 'FORMLABS-FORM-WASH-V2-120V', 'quantity' => 1, 'cost' => 950.00, 'serial_numbers' => ['WASHV2-120V-DEMO-0001']],
            ],
        ],
    ];

    public function run(): void
    {
        $this->call(PackageTypeSeeder::class);
        $this->call(DentalCatalogSeeder::class);

        /** @var array<string, ProductVariant> $variants keyed by SKU */
        $variants = ProductVariant::query()->get()->keyBy('sku')->all();
        $suppliers = $this->seedPurchasingData($variants);
        $customers = $this->seedDemoCustomers();

        $this->seedWarehouseOperations($variants, $suppliers);
        $this->seedInventoryOperationWorkflow($variants, $suppliers, $customers);
        $this->seedPricingDemo($variants, $customers);
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
            ['supplier' => 'FORMLABS-US', 'sku' => 'FORMLABS-FORM-4B-120V', 'item' => 'F4B-DENTAL-120V', 'cost' => 3200.00],
            ['supplier' => 'FORMLABS-US', 'sku' => 'FORMLABS-FORM-4B-PREMIUM-230V', 'item' => 'F4B-PREMIUM-230V', 'cost' => 3950.00],
            ['supplier' => 'FORMLABS-US', 'sku' => 'FORMLABS-PRECISION-MODEL-1L', 'item' => 'RS-F4-PM-1L', 'cost' => 60.00],
            ['supplier' => 'FORMLABS-US', 'sku' => 'FORMLABS-PRECISION-MODEL-5L', 'item' => 'RS-F4-PM-5L', 'cost' => 285.00],
            ['supplier' => 'FORMLABS-US', 'sku' => 'FORMLABS-SURGICAL-GUIDE-1L', 'item' => 'RS-F4-SG-1L', 'cost' => 95.00],
            ['supplier' => 'FORMLABS-US', 'sku' => 'FORMLABS-SURGICAL-GUIDE-5L', 'item' => 'RS-F4-SG-5L', 'cost' => 450.00],
            ['supplier' => 'FORMLABS-US', 'sku' => 'FORMLABS-FORM-WASH-V2', 'item' => 'FWV2-EU', 'cost' => 950.00],
            ['supplier' => 'FORMLABS-US', 'sku' => 'FORMLABS-FORM-WASH-V2-120V', 'item' => 'FWV2-120V', 'cost' => 950.00],
            ['supplier' => 'DENTSPLY-MENA', 'sku' => 'DENTSPLY-PRIMEPRINT-SOLUTION', 'item' => 'PPS-MENA', 'cost' => 12800.00],
            ['supplier' => 'DENTSPLY-MENA', 'sku' => 'DENTSPLY-PRIMEPRINT-SOLUTION-110V', 'item' => 'PPS-110V-MENA', 'cost' => 12800.00],
            ['supplier' => 'DENTSPLY-MENA', 'sku' => 'DENTSPLY-PRIMEPRINT-PPU', 'item' => 'PPU-MENA', 'cost' => 4900.00],
            ['supplier' => 'DENTSPLY-MENA', 'sku' => 'DENTSPLY-PRIMEPRINT-PPU-230V', 'item' => 'PPU-230V-MENA', 'cost' => 4900.00],
            ['supplier' => 'DENTSPLY-MENA', 'sku' => 'DENTSPLY-DENTAL-STONE-25KG', 'item' => 'DS-25KG-MENA', 'cost' => 22.00],
            ['supplier' => 'IVOCLAR-LEVANT', 'sku' => 'IVOCLAR-PROGRAPRINT-PR5', 'item' => 'PR5-LEV', 'cost' => 9800.00],
            ['supplier' => 'IVOCLAR-LEVANT', 'sku' => 'IVOCLAR-PROGRAPRINT-PR5-100-240V', 'item' => 'PR5-UNIVERSAL-LEV', 'cost' => 9800.00],
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
        $actor = $this->demoActor();

        if (InventoryReceipt::query()->where('supplier_reference', 'FL-INV-2026-1001')->exists()) {
            $this->seedWarehouseCoverageReceipts($warehouses, $variants, $suppliers, $actor);

            return;
        }

        $this->seedMainReceipt($warehouses['MAIN'], $variants, $suppliers['FORMLABS-US'], $actor);
        $this->seedColdReceipt($warehouses['COLD'], $variants, $suppliers['FORMLABS-US'], $actor);
        $this->seedWarehouseCoverageReceipts($warehouses, $variants, $suppliers, $actor);
        $this->seedTransfers($warehouses, $variants, $actor);
        $this->seedAdjustments($warehouses, $variants, $actor);
        $this->seedReturnAndReservation($warehouses['MAIN'], $variants, $actor);
        $this->seedLowStockAlert($warehouses['MAIN'], $variants['FORMLABS-FORM-4B']);
    }

    /** @return array<string, Warehouse> */
    private function seedWarehouses(): array
    {
        $definitions = [
            'MAIN' => ['name' => 'Main Clinic Store', 'address' => 'Dubai Healthcare City, Dubai, United Arab Emirates', 'latitude' => 25.2353, 'longitude' => 55.3197],
            'COLD' => ['name' => 'Cold Chain Storage', 'address' => 'Mussafah, Abu Dhabi, United Arab Emirates', 'latitude' => 24.3561, 'longitude' => 54.5234],
            'BENCH' => ['name' => 'Repair Bench', 'address' => 'Industrial Area 2, Sharjah, United Arab Emirates', 'latitude' => 25.3125, 'longitude' => 55.3947],
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

        $this->call([
            InventoryPermissionSeeder::class,
            CrmPermissionSeeder::class,
        ]);

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
     * @param  array<string, Supplier>  $suppliers
     */
    private function seedWarehouseCoverageReceipts(array $warehouses, array $variants, array $suppliers, User $actor): void
    {
        foreach (self::WarehouseCoverageReceipts as $definition) {
            if (InventoryReceipt::query()->where('supplier_reference', $definition['reference'])->exists()) {
                continue;
            }

            $receipt = InventoryReceipt::query()->create([
                'warehouse_id' => $warehouses[$definition['warehouse']]->getKey(),
                'supplier_id' => $suppliers[$definition['supplier']]->getKey(),
                'supplier_reference' => $definition['reference'],
                'notes' => $definition['notes'],
            ]);

            foreach ($definition['items'] as $item) {
                $variant = $variants[$item['sku']];
                $receiptItem = $receipt->items()->create([
                    'product_variant_id' => $variant->getKey(),
                    'quantity' => $item['quantity'],
                    'purchase_cost' => $item['cost'],
                    'lot_number' => $item['lot_number'] ?? null,
                    'expires_at' => isset($item['expires_in_days']) ? now()->addDays($item['expires_in_days']) : null,
                ]);

                foreach ($item['serial_numbers'] ?? [] as $serialNumber) {
                    SerializedInventoryUnit::query()->create([
                        'product_variant_id' => $variant->getKey(),
                        'inventory_receipt_item_id' => $receiptItem->getKey(),
                        'serial_number' => $serialNumber,
                    ]);
                }
            }

            app(InventoryReceivingService::class)->confirm($receipt, $actor);
        }
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
     * @param  array{smile: User, bright: User}  $customers
     * @param  array<string, ProductVariant>  $variants  keyed by SKU
     * @param  array<string, Supplier>  $suppliers  keyed by supplier code
     */
    private function seedInventoryOperationWorkflow(array $variants, array $suppliers, array $customers): void
    {
        if (InventoryOperation::query()->where('notes', 'Demo workflow: delivered Formlabs replenishment.')->exists()) {
            $this->ensureSeededDeliveries($customers);

            return;
        }

        $actor = $this->demoActor();
        $main = $this->warehouseByCode('MAIN');
        $cold = $this->warehouseByCode('COLD');
        $bench = $this->warehouseByCode('BENCH');
        $mainResinPackage = $this->packageByName('Main resin carton');
        $coldResinPackage = $this->packageByName('Cold-chain resin carton');
        $service = app(InventoryOperationService::class);
        $smileCustomer = $customers['smile']->customerProfile()->firstOrFail();

        $order = Order::query()->updateOrCreate(
            ['order_number' => 'SO-2026-0001'],
            [
                'customer_id' => $smileCustomer->getKey(),
                'status' => 'ready',
                'notes' => 'Demo order for Smile Dental Clinic delivery.',
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ],
        );
        $order->lines()->updateOrCreate(
            ['product_variant_id' => $variants['FORMLABS-PRECISION-MODEL-1L']->getKey()],
            [
                'quantity' => 3,
                'unit_id' => $variants['FORMLABS-PRECISION-MODEL-1L']->unit_id,
            ],
        );

        $completedReceipt = InventoryOperation::query()->create([
            'operation_type' => OperationType::Receipt,
            'destination_warehouse_id' => $main->getKey(),
            'supplier_id' => $suppliers['FORMLABS-US']->getKey(),
            'supplier_reference' => 'PO-FL-2026-1021',
            'scheduled_at' => now()->subDays(2),
            'responsible_id' => $actor->getKey(),
            'notes' => 'Demo workflow: delivered Formlabs replenishment.',
        ]);
        // The resin is an expiry material, so its receipt line carries the batch it creates.
        $completedReceipt->lines()->create([
            'product_variant_id' => $variants['FORMLABS-PRECISION-MODEL-1L']->getKey(),
            'quantity' => 12,
            'unit_id' => $variants['FORMLABS-PRECISION-MODEL-1L']->unit_id,
            'unit_cost' => 60,
            'package_id' => $mainResinPackage->getKey(),
            'lot_number' => 'LOT-PRECISION-OP-01',
            'expires_at' => now()->addMonths(9)->toDateString(),
        ]);
        $service->markReady($completedReceipt);
        $service->complete($completedReceipt->refresh(), $actor);

        $delivery = InventoryOperation::query()->create([
            'operation_type' => OperationType::Delivery,
            'source_warehouse_id' => $main->getKey(),
            'customer_id' => $smileCustomer->getKey(),
            'delivery_type' => DeliveryType::Outer,
            'source_document_type' => Order::class,
            'source_document_id' => $order->getKey(),
            'scheduled_at' => now()->addDay(),
            'responsible_id' => $actor->getKey(),
            'notes' => 'Demo workflow: reserved resin for Smile Dental Clinic.',
        ]);
        // An outbound line for an expiry material names the batch it draws from. Taking the
        // earliest-expiring usable batch is the same first-expired-first-out choice the line
        // editor pre-selects for an operator.
        $delivery->lines()->create([
            'product_variant_id' => $variants['FORMLABS-PRECISION-MODEL-1L']->getKey(),
            'quantity' => 3,
            'unit_id' => $variants['FORMLABS-PRECISION-MODEL-1L']->unit_id,
            'unit_cost' => 84,
            'package_id' => $mainResinPackage->getKey(),
            'inventory_lot_id' => $this->earliestUsableLotId($variants['FORMLABS-PRECISION-MODEL-1L'], $main),
        ]);
        $service->markReady($delivery);
        $this->seedDeliveryDocuments($delivery);
        $shipment = Shipment::query()->firstOrCreate(
            ['inventory_operation_id' => $delivery->getKey()],
            [
                'order_id' => $order->getKey(),
                'warehouse_id' => $main->getKey(),
                'tracking_number' => 'TRK-DEMO-SMILE-2026-0001',
            ],
        );
        $this->seedShipmentAttachments($shipment);

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
            'inventory_lot_id' => $this->earliestUsableLotId($variants['FORMLABS-SURGICAL-GUIDE-1L'], $cold),
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
            'customer_id' => $customers['bright']->customerProfile()->firstOrFail()->getKey(),
            'delivery_type' => DeliveryType::Inner,
            'source_document_type' => 'service_order',
            'source_document_id' => 2026002,
            'scheduled_at' => now()->addDays(3),
            'responsible_id' => $actor->getKey(),
            'notes' => 'Demo workflow: waiting for unavailable Primeprint PPU stock.',
        ]);
        // A machine line names the specific device it moves. This device is registered but not
        // standing in the bench warehouse, which is exactly the shortfall that holds the
        // operation at Waiting rather than letting it reach Ready.
        $waitingDelivery->lines()->create([
            'product_variant_id' => $variants['DENTSPLY-PRIMEPRINT-PPU']->getKey(),
            'quantity' => 1,
            'unit_id' => $variants['DENTSPLY-PRIMEPRINT-PPU']->unit_id,
            'unit_cost' => 4900,
            'serialized_inventory_unit_id' => SerializedInventoryUnit::query()->firstOrCreate(
                ['serial_number' => 'PRIMEPRINT-PPU-DEMO-0001'],
                ['product_variant_id' => $variants['DENTSPLY-PRIMEPRINT-PPU']->getKey()],
            )->getKey(),
        ]);
        $service->markReady($waitingDelivery);
        $this->seedDeliveryDocuments($waitingDelivery);
    }

    /** @param array{smile: User, bright: User} $customers */
    private function ensureSeededDeliveries(array $customers): void
    {
        $definitions = [
            'Demo workflow: reserved resin for Smile Dental Clinic.' => [
                'customer_id' => $customers['smile']->customerProfile()->firstOrFail()->getKey(),
                'delivery_type' => DeliveryType::Outer,
            ],
            'Demo workflow: waiting for unavailable Primeprint PPU stock.' => [
                'customer_id' => $customers['bright']->customerProfile()->firstOrFail()->getKey(),
                'delivery_type' => DeliveryType::Inner,
            ],
        ];

        foreach ($definitions as $notes => $attributes) {
            $delivery = InventoryOperation::query()->where('notes', $notes)->first();

            if (! $delivery instanceof InventoryOperation) {
                continue;
            }

            $delivery->forceFill($attributes)->save();
            $this->seedDeliveryDocuments($delivery);
        }
    }

    private function seedDeliveryDocuments(InventoryOperation $delivery): void
    {
        foreach (DeliveryDocument::cases() as $document) {
            if ($delivery->getFirstMedia($document->value) instanceof Media) {
                continue;
            }

            $delivery
                ->addMediaFromString(self::TestingPlaceholderPdf)
                ->usingFileName('delivery-'.$this->modelId($delivery).'-'.$document->value.'.pdf')
                ->usingName($document->label())
                ->withCustomProperties(['seeded_delivery_document' => true])
                ->toMediaCollection($document->value, 'local');
        }
    }

    private function seedShipmentAttachments(Shipment $shipment): void
    {
        foreach (['packing-photo', 'signed-delivery-note'] as $attachmentName) {
            $hasSeededAttachment = $shipment->getMedia('attachments')->contains(
                static fn (Media $media): bool => $media->getCustomProperty('seeded_delivery_attachment') === $attachmentName,
            );

            if ($hasSeededAttachment) {
                continue;
            }

            $shipment
                ->addMediaFromString(self::TestingPlaceholderPdf)
                ->usingFileName($shipment->tracking_number.'-'.$attachmentName.'.pdf')
                ->usingName($attachmentName)
                ->withCustomProperties(['seeded_delivery_attachment' => $attachmentName])
                ->toMediaCollection('attachments', 'local');
        }
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
     * The batch an outbound demo line should draw from: first-expired, first-out, matching what
     * {@see InventoryLotService::availableLots()} offers an operator.
     */
    private function earliestUsableLotId(ProductVariant $variant, Warehouse $warehouse): int
    {
        $lot = app(InventoryLotService::class)
            ->availableLots($variant->id, $warehouse->id)
            ->first();

        if (! $lot instanceof InventoryLot) {
            throw new LogicException(sprintf('The demo seeder expected a usable lot for [%s] at [%s].', $variant->sku, $warehouse->code));
        }

        return $lot->id;
    }

    /**
     * Gives the pricing screens (tiers, customer assignments, price history,
     * floor overrides) at least one believable record. Runs once: a pricing
     * tier is used as the idempotency marker, since pricing rows have no
     * natural unique key to `updateOrCreate` against.
     *
     * @param  array{smile: User, bright: User}  $customers
     * @param  array<string, ProductVariant>  $variants  keyed by SKU
     */
    private function seedPricingDemo(array $variants, array $customers): void
    {
        if (PricingTier::query()->exists()) {
            return;
        }

        $actor = $this->demoActor();
        $pricingService = app(ProductPricingService::class);
        $tierService = app(PricingTierService::class);
        $smileCustomerId = $this->modelId($customers['smile']);

        $loyaltyTier = $tierService->save(null, new PricingTierData(
            name: 'Loyalty Clinics',
            tierType: PricingTierType::General,
            discountType: PricingTierDiscountType::Percentage,
            discountValue: 10.0,
            isActive: true,
        ), $actor);

        $tierService->save(null, new PricingTierData(
            name: 'Smile Dental Clinic — VIP',
            tierType: PricingTierType::CustomerSpecific,
            discountType: PricingTierDiscountType::Percentage,
            discountValue: 15.0,
            customerUserId: $smileCustomerId,
            isActive: true,
        ), $actor);

        $tierService->assignGeneralTier($customers['bright'], $loyaltyTier, $actor);

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

    private function modelId(User|ProductVariant|InventoryOperation $model): int
    {
        $id = $model->getKey();

        if (! is_int($id)) {
            throw new LogicException('A persisted model with an integer key is required.');
        }

        return $id;
    }

    /** @return array{smile: User, bright: User} */
    private function seedDemoCustomers(): array
    {
        $customers = [
            'smile' => User::query()->updateOrCreate([
                'email' => 'smile-dental-clinic@ierp.com',
            ], [
                'name' => 'Smile Dental Clinic',
                'password' => Hash::make('password'),
                'user_type' => UserType::Customer,
            ]),
            'bright' => User::query()->updateOrCreate([
                'email' => 'bright-orthodontics@ierp.com',
            ], [
                'name' => 'Bright Orthodontics',
                'password' => Hash::make('password'),
                'user_type' => UserType::Customer,
            ]),
        ];

        $profiles = [
            'smile' => [
                'customer_code' => 'DEMO-SMILE',
                'company_name' => 'Smile Dental Clinic',
                'email' => 'smile-dental-clinic@ierp.com',
                'phone' => '+971 4 555 0101',
                'address' => 'Dubai Healthcare City, Dubai, United Arab Emirates',
                'country' => 'AE',
                'city' => 'Dubai',
                'latitude' => 25.2353,
                'longitude' => 55.3197,
            ],
            'bright' => [
                'customer_code' => 'DEMO-BRIGHT',
                'company_name' => 'Bright Orthodontics',
                'email' => 'bright-orthodontics@ierp.com',
                'phone' => '+971 2 555 0102',
                'address' => 'Al Danah, Abu Dhabi, United Arab Emirates',
                'country' => 'AE',
                'city' => 'Abu Dhabi',
                'latitude' => 24.4539,
                'longitude' => 54.3773,
            ],
        ];

        foreach ($profiles as $key => $profile) {
            CustomerProfile::query()->updateOrCreate(
                ['user_id' => $customers[$key]->getKey()],
                [...$profile, 'is_active' => true],
            );
        }

        return $customers;
    }
}
