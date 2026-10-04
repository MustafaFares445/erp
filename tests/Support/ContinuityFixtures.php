<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\MaintenanceKind;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\CustomerProfile;
use App\Models\InventoryStock;
use App\Models\MaintenanceRecord;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\SupportPermissionSeeder;

/** Shared builders for the loaner and supplier-repair (RMA) tests. */
final class ContinuityFixtures
{
    public static function seedPermissions(): void
    {
        (new SupportPermissionSeeder)->run();
        (new InventoryPermissionSeeder)->run();
    }

    /** A support manager who may also move stock (Support and Inventory authorization). */
    public static function operator(): User
    {
        $user = User::factory()->admin()->create();
        $user->assignRole('Support Manager');
        $user->assignRole('Warehouse Manager');

        return $user;
    }

    /** A support manager without any Inventory permission. */
    public static function supportOnly(): User
    {
        return InstallationFixtures::manager();
    }

    public static function warehouse(): Warehouse
    {
        return Warehouse::factory()->create();
    }

    /** A saleable serialized unit held in a warehouse, with a matching stock balance. */
    public static function warehouseUnit(?ProductVariant $variant = null, ?Warehouse $warehouse = null, StockCondition $condition = StockCondition::Saleable): SerializedInventoryUnit
    {
        $variant ??= ProductVariant::factory()->create();
        $warehouse ??= self::warehouse();

        $stock = InventoryStock::query()->where('product_variant_id', $variant->id)->where('warehouse_id', $warehouse->id)->first() ?? new InventoryStock;
        $onHand = (float) ($stock->on_hand_quantity ?? 0) + 1;
        $damaged = (float) ($stock->damaged_quantity ?? 0) + ($condition === StockCondition::Damaged ? 1 : 0);
        $stock->forceFill([
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'on_hand_quantity' => $onHand,
            'reserved_quantity' => 0,
            'damaged_quantity' => $damaged,
            'available_quantity' => $onHand - $damaged,
        ])->save();

        return SerializedInventoryUnit::factory()->create([
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'status' => $condition === StockCondition::Damaged ? SerializedInventoryUnitStatus::Damaged : SerializedInventoryUnitStatus::Available,
            'custody_type' => SerializedCustodyType::Warehouse,
            'custody_reference_type' => 'warehouse',
            'custody_reference_id' => $warehouse->id,
            'stock_condition' => $condition,
        ]);
    }

    /** @return array{0: CustomerProfile, 1: SerializedInventoryUnit, 2: MaintenanceRecord} customer, their unit, the repair request */
    public static function repairScenario(MaintenanceKind $kind = MaintenanceKind::Corrective): array
    {
        $customer = CustomerProfile::factory()->create();
        $variant = ProductVariant::factory()->create();
        $unit = SerializedInventoryUnit::factory()->create([
            'product_variant_id' => $variant->id,
            'status' => SerializedInventoryUnitStatus::Delivered,
            'custody_type' => SerializedCustodyType::Customer,
            'custody_reference_type' => 'customer',
            'custody_reference_id' => $customer->getKey(),
        ]);
        $record = MaintenanceRecord::factory()->create([
            'customer_id' => $customer->getKey(),
            'serialized_inventory_unit_id' => $unit->getKey(),
            'maintenance_kind' => $kind,
        ]);

        return [$customer, $unit, $record];
    }

    /** A loaner unit of the same product as the customer's unit, held in a warehouse. */
    public static function loanerFor(SerializedInventoryUnit $original, ?Warehouse $warehouse = null): SerializedInventoryUnit
    {
        $variant = ProductVariant::factory()->create(['product_id' => $original->productVariant->product_id]);

        return self::warehouseUnit($variant, $warehouse);
    }

    /** Moves a customer's unit into a warehouse, as an Inventory return would, so it can be shipped. */
    public static function receiveIntoWarehouse(SerializedInventoryUnit $unit): void
    {
        $warehouse = self::warehouse();
        $stock = new InventoryStock;
        $stock->forceFill([
            'product_variant_id' => $unit->product_variant_id,
            'warehouse_id' => $warehouse->id,
            'on_hand_quantity' => 1,
            'reserved_quantity' => 0,
            'damaged_quantity' => 0,
            'available_quantity' => 1,
        ])->save();
        $unit->forceFill([
            'warehouse_id' => $warehouse->id,
            'status' => SerializedInventoryUnitStatus::Available,
            'custody_type' => SerializedCustodyType::Warehouse,
            'custody_reference_type' => 'warehouse',
            'custody_reference_id' => $warehouse->id,
            'stock_condition' => StockCondition::Saleable,
        ])->save();
    }

    public static function product(): Product
    {
        return Product::factory()->create();
    }
}
