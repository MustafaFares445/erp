<?php

declare(strict_types=1);

use App\Models\InventoryOperation;
use App\Models\InventoryReservation;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('removes retired inventory persistence from the active schema', function (): void {
    expect(Schema::hasTable('inventory_receipts'))->toBeFalse()
        ->and(Schema::hasTable('inventory_receipt_items'))->toBeFalse()
        ->and(Schema::hasTable('stock_transfers'))->toBeFalse()
        ->and(Schema::hasTable('stock_transfer_items'))->toBeFalse()
        ->and(Schema::hasTable('stock_reservations'))->toBeFalse()
        ->and(Schema::hasColumn('serialized_inventory_units', 'inventory_receipt_item_id'))->toBeFalse()
        ->and(Schema::hasColumn('inventory_operations', 'legacy_receipt_id'))->toBeFalse()
        ->and(Schema::hasColumn('inventory_operations', 'legacy_transfer_id'))->toBeFalse()
        ->and(Schema::hasColumn('inventory_reservations', 'legacy_stock_reservation_id'))->toBeFalse();
});

it('contains no retired receipt transfer reservation or migration bridge classes', function (): void {
    expect(class_exists('App\\Models\\InventoryReceipt'))->toBeFalse()
        ->and(class_exists('App\\Models\\InventoryReceiptItem'))->toBeFalse()
        ->and(class_exists('App\\Models\\StockTransfer'))->toBeFalse()
        ->and(class_exists('App\\Models\\StockTransferItem'))->toBeFalse()
        ->and(class_exists('App\\Models\\StockReservation'))->toBeFalse()
        ->and(class_exists('Database\\Factories\\InventoryReceiptFactory'))->toBeFalse()
        ->and(class_exists('Database\\Factories\\InventoryReceiptItemFactory'))->toBeFalse()
        ->and(class_exists('Database\\Factories\\StockTransferFactory'))->toBeFalse()
        ->and(class_exists('Database\\Factories\\StockTransferItemFactory'))->toBeFalse()
        ->and(class_exists('Database\\Factories\\StockReservationFactory'))->toBeFalse()
        ->and(class_exists('App\\Enums\\TransferStatus'))->toBeFalse()
        ->and(class_exists('App\\Services\\Inventory\\LegacyReceiptOperationConverter'))->toBeFalse()
        ->and(class_exists('App\\Services\\Inventory\\InventoryOperationBackfiller'))->toBeFalse()
        ->and(class_exists('App\\Services\\Inventory\\OperationBackfillReconciler'))->toBeFalse();
});

it('seeds demo receipts only through canonical inventory operations', function (): void {
    $source = (string) file_get_contents(database_path('seeders/InventoryDemoSeeder.php'));

    expect($source)
        ->toContain('seedCanonicalReceipt')
        ->toContain('InventoryOperationService::class')
        ->not->toContain('InventoryReceipt::')
        ->not->toContain('InventoryReceiptItem')
        ->not->toContain('LegacyReceiptOperationConverter');
});

it('keeps canonical inventory models usable after legacy persistence deletion', function (): void {
    $source = Warehouse::factory()->create();
    $destination = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    $operation = InventoryOperation::factory()->internalTransfer()->create([
        'source_warehouse_id' => $source->getKey(),
        'destination_warehouse_id' => $destination->getKey(),
    ]);

    $reservation = InventoryReservation::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $source->getKey(),
    ]);

    $serial = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
    ]);

    expect($operation->exists)->toBeTrue()
        ->and($reservation->exists)->toBeTrue()
        ->and($serial->exists)->toBeTrue();
});
