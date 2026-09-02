<?php

declare(strict_types=1);

use App\Models\InventoryOperation;
use App\Models\InventoryReservation;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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

it('keeps demo seeders free of retired inventory runtime classes', function (): void {
    $inventorySource = (string) file_get_contents(database_path('seeders/InventoryDemoSeeder.php'));
    $catalogSource = (string) file_get_contents(database_path('seeders/DentalCatalogSeeder.php'));
    $supportSource = (string) file_get_contents(database_path('seeders/SupportDemoSeeder.php'));

    expect($inventorySource)
        ->toContain('seedCanonicalReceipt')
        ->toContain('InventoryOperationService::class')
        ->not->toContain('InventoryReceipt::')
        ->not->toContain('InventoryReceiptItem')
        ->not->toContain('LegacyReceiptOperationConverter')
        ->and($catalogSource)
        ->not->toContain('StockTransfer::')
        ->not->toContain('StockTransferItem')
        ->and($supportSource)
        ->not->toContain('InventoryReceipt::')
        ->not->toContain('InventoryReceiptItem')
        ->not->toContain('LegacyReceiptOperationConverter');
});

it('contains no retired inventory imports in runtime code or demo seeders', function (): void {
    $files = collect([
        ...File::allFiles(app_path()),
        ...File::allFiles(database_path('factories')),
        ...File::allFiles(database_path('seeders')),
    ]);

    $retiredReferences = [
        'use App\\Models\\InventoryReceipt;',
        'use App\\Models\\InventoryReceiptItem;',
        'use App\\Models\\StockTransfer;',
        'use App\\Models\\StockTransferItem;',
        'use App\\Models\\StockReservation;',
        'use App\\Services\\Inventory\\LegacyReceiptOperationConverter;',
        'use App\\Services\\Inventory\\InventoryOperationBackfiller;',
        'use App\\Services\\Inventory\\OperationBackfillReconciler;',
        'inventory_receipt_item_id',
    ];

    $violations = $files
        ->filter(static fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
        ->flatMap(static function (SplFileInfo $file) use ($retiredReferences): array {
            $source = (string) file_get_contents($file->getPathname());
            $matches = array_values(array_filter(
                $retiredReferences,
                static fn (string $reference): bool => str_contains($source, $reference),
            ));

            return $matches === []
                ? []
                : [$file->getPathname() => $matches];
        });

    expect($violations)->toBeEmpty();
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
