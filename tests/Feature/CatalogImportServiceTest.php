<?php

declare(strict_types=1);

use App\Enums\InventoryImportItemStatus;
use App\Enums\InventoryImportRunStatus;
use App\Enums\SerializedInventoryUnitStatus;
use App\Jobs\ApplyCatalogImport;
use App\Jobs\ParseCatalogImport;
use App\Models\InventoryImportRun;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\CatalogImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');

    ProductAttribute::query()->forceCreate([
        'name' => 'Color',
        'code' => 'color',
        'data_type' => 'select',
        'is_active' => true,
    ]);
    $color = ProductAttribute::query()->where('code', 'color')->firstOrFail();
    ProductAttributeValue::query()->forceCreate([
        'product_attribute_id' => $color->getKey(),
        'value' => 'Red',
        'is_active' => true,
    ]);
    ProductAttribute::query()->forceCreate([
        'name' => 'Material',
        'code' => 'material',
        'data_type' => 'text',
        'is_active' => true,
    ]);
    $material = ProductAttribute::query()->where('code', 'material')->firstOrFail();
    ProductAttributeValue::query()->forceCreate([
        'product_attribute_id' => $material->getKey(),
        'value' => 'Steel',
        'is_active' => true,
    ]);
    Warehouse::factory()->create(['code' => 'WH-A']);
});

it('queues only private XLSX files from the catalog import directory', function (): void {
    Queue::fake();
    $actor = User::factory()->create();
    $service = app(CatalogImportService::class);
    $path = 'catalog-imports/queued.xlsx';
    Storage::disk('local')->put($path, 'workbook');

    $run = $service->queueStoredFile($path, $actor);

    expect($run->status)->toBe(InventoryImportRunStatus::Queued)
        ->and(fn () => $service->queueStoredFile('catalog-imports/../private.xlsx', $actor))
        ->toThrow(DomainException::class);

    Queue::assertPushed(
        ParseCatalogImport::class,
        fn (ParseCatalogImport $job): bool => $job->importRunId === $run->getKey(),
    );
});

it('generates dynamic columns and parses mixed rows without mutating the catalog', function (): void {
    $service = app(CatalogImportService::class);
    $templatePath = Storage::disk('local')->path('catalog-imports/template.xlsx');
    $service->writeTemplate($templatePath);

    expect(catalogWorkbookHeaders($templatePath))
        ->toContain('warehouse_code', 'quantity', 'attribute_color', 'attribute_material');

    $run = parseMixedCatalogWorkbook($service, User::factory()->create());

    expect($run->fresh()->status)->toBe(InventoryImportRunStatus::ReadyWithErrors)
        ->and($run->fresh()->total_rows)->toBe(5)
        ->and($run->fresh()->valid_rows)->toBe(3)
        ->and($run->fresh()->failed_rows)->toBe(2)
        ->and($run->items()->where('status', InventoryImportItemStatus::Valid->value)->count())->toBe(3)
        ->and($run->items()->where('status', InventoryImportItemStatus::Invalid->value)->count())->toBe(2)
        ->and(Product::query()->count())->toBe(0)
        ->and(InventoryStock::query()->count())->toBe(0);
});

it('queues and applies valid catalog, device, lot, and attribute rows exactly once', function (): void {
    Queue::fake();
    $actor = User::factory()->create();
    $service = app(CatalogImportService::class);
    $run = parseMixedCatalogWorkbook($service, $actor);

    $service->confirm($run, $actor);

    expect($run->fresh()->status)->toBe(InventoryImportRunStatus::Applying);
    Queue::assertPushed(
        ApplyCatalogImport::class,
        fn (ApplyCatalogImport $job): bool => $job->importRunId === $run->getKey(),
    );

    $service->apply($run, $actor);

    $finished = $run->fresh();
    $serialized = SerializedInventoryUnit::query()->where('serial_number', 'SER-001')->firstOrFail();
    $textValue = ProductAttributeValue::query()->whereRaw('LOWER(value) = ?', ['steel'])->firstOrFail();
    $serializedResult = $run->items()->where('row_number', 3)->firstOrFail()->result;
    $lotResult = $run->items()->where('row_number', 4)->firstOrFail()->result;

    expect($finished->status)->toBe(InventoryImportRunStatus::ConfirmedWithErrors)
        ->and($finished->created_rows)->toBe(3)
        ->and($finished->updated_rows)->toBe(0)
        ->and($finished->applied_rows)->toBe(3)
        ->and($finished->rejected_rows)->toBe(2)
        ->and(ProductVariant::query()->count())->toBe(3)
        ->and($serialized->iot_number)->toBe('IOT-001')
        ->and($serialized->status)->toBe(SerializedInventoryUnitStatus::Available)
        ->and(InventoryLot::query()->where('lot_number', 'LOT-001')->exists())->toBeTrue()
        ->and(InventoryMovement::query()->where('movement_type', 'receipt')->count())->toBe(2)
        ->and((float) InventoryStock::query()->sum('on_hand_quantity'))->toBe(6.0)
        ->and($textValue->variantAssignments()->count())->toBe(1)
        ->and(ProductAttributeValue::query()->where('product_attribute_id', $textValue->product_attribute_id)->count())->toBe(1)
        ->and($serializedResult)->toHaveKeys([
            'product_id',
            'product_variant_id',
            'inventory_receipt_id',
            'inventory_receipt_item_id',
            'serialized_inventory_unit_id',
        ])
        ->and($lotResult)->toHaveKey('inventory_lot_id')
        ->and(Storage::disk('local')->exists($finished->result_path))->toBeTrue()
        ->and(Storage::disk('local')->exists($finished->summary_path))->toBeTrue();

    $service->apply($run, $actor);

    expect(ProductVariant::query()->count())->toBe(3)
        ->and(SerializedInventoryUnit::query()->count())->toBe(1)
        ->and(InventoryMovement::query()->count())->toBe(2)
        ->and((float) InventoryStock::query()->sum('on_hand_quantity'))->toBe(6.0);
});

it('rolls back only the receipt group that fails at runtime', function (): void {
    Queue::fake();
    $actor = User::factory()->create();
    $service = app(CatalogImportService::class);
    $secondWarehouse = Warehouse::factory()->create(['code' => 'WH-B']);
    $path = 'catalog-imports/group-isolation.xlsx';
    writeCatalogWorkbook(Storage::disk('local')->path($path), catalogImportHeaders(), [
        catalogImportRow('FAIL-SKU', ['warehouse_code' => 'WH-A', 'quantity' => 1, 'serial_number' => 'DUPLICATE-SERIAL']),
        catalogImportRow('PASS-SKU', ['warehouse_code' => $secondWarehouse->code, 'quantity' => 2]),
    ]);
    $run = InventoryImportRun::factory()->create(['file_path' => $path, 'created_by' => $actor->getKey()]);

    $service->parse($run);
    $existingVariant = ProductVariant::factory()->create(['track_serials' => true]);
    SerializedInventoryUnit::factory()->for($existingVariant, 'productVariant')->create([
        'serial_number' => 'DUPLICATE-SERIAL',
    ]);
    $service->confirm($run, $actor);
    $service->apply($run, $actor);

    $failedRow = $run->items()->where('row_number', 2)->firstOrFail();
    $appliedRow = $run->items()->where('row_number', 3)->firstOrFail();

    expect($run->fresh()->status)->toBe(InventoryImportRunStatus::ConfirmedWithErrors)
        ->and($failedRow->status)->toBe(InventoryImportItemStatus::Rejected)
        ->and($failedRow->runtime_error)->not->toBeNull()
        ->and($appliedRow->status)->toBe(InventoryImportItemStatus::Applied)
        ->and(ProductVariant::query()->where('sku', 'FAIL-SKU')->exists())->toBeFalse()
        ->and((float) InventoryStock::query()->where('warehouse_id', $secondWarehouse->getKey())->value('on_hand_quantity'))->toBe(2.0);
});

it('rejects confirmation when no rows are valid', function (): void {
    $actor = User::factory()->create();
    $service = app(CatalogImportService::class);
    $path = 'catalog-imports/invalid.xlsx';
    writeCatalogWorkbook(Storage::disk('local')->path($path), catalogImportHeaders(), [
        catalogImportRow('BAD-SKU', ['warehouse_code' => 'WH-A', 'quantity' => 2, 'serial_number' => 'SER-BAD']),
    ]);
    $run = InventoryImportRun::factory()->create(['file_path' => $path, 'created_by' => $actor->getKey()]);

    $service->parse($run);

    expect($run->fresh()->status)->toBe(InventoryImportRunStatus::Invalid)
        ->and(fn () => $service->confirm($run, $actor))->toThrow(DomainException::class);
});

it('keeps stock and movement writes outside the importer implementation', function (): void {
    $source = file_get_contents(app_path('Services/Inventory/CatalogImportApplicationService.php'));

    expect($source)->not->toBeFalse()
        ->and($source)->not->toContain('InventoryStock::')
        ->and($source)->not->toContain('InventoryMovement::')
        ->and($source)->not->toContain('InventoryLot::query()->create');
});

function parseMixedCatalogWorkbook(CatalogImportService $service, User $actor): InventoryImportRun
{
    $path = 'catalog-imports/mixed.xlsx';
    writeCatalogWorkbook(Storage::disk('local')->path($path), catalogImportHeaders(), [
        catalogImportRow('CAT-001', ['attribute_color' => 'Red', 'attribute_material' => 'steel']),
        catalogImportRow('SER-001-SKU', [
            'warehouse_code' => 'WH-A',
            'quantity' => 1,
            'track_serials' => 'true',
            'serial_number' => 'SER-001',
            'iot_number' => 'IOT-001',
        ]),
        catalogImportRow('LOT-001-SKU', [
            'warehouse_code' => 'WH-A',
            'quantity' => 5,
            'track_expiry' => 'true',
            'lot_number' => 'LOT-001',
            'expires_at' => now()->addMonth()->toDateString(),
        ]),
        catalogImportRow('BAD-SERIAL', [
            'warehouse_code' => 'WH-A',
            'quantity' => 2,
            'track_serials' => 'true',
            'serial_number' => 'SER-002',
        ]),
        catalogImportRow('BAD-ATTRIBUTE', ['attribute_color' => 'Blue']),
    ]);
    $run = InventoryImportRun::factory()->create([
        'file_path' => $path,
        'created_by' => $actor->getKey(),
    ]);

    $service->parse($run);

    return $run;
}

/** @return list<string> */
function catalogImportHeaders(): array
{
    return [
        'sku',
        'product_name',
        'variant_name',
        'track_serials',
        'track_expiry',
        'warehouse_code',
        'quantity',
        'serial_number',
        'iot_number',
        'lot_number',
        'expires_at',
        'attribute_color',
        'attribute_material',
    ];
}

/** @param array<string, int|string> $overrides @return array<string, int|string> */
function catalogImportRow(string $sku, array $overrides = []): array
{
    return [
        'sku' => $sku,
        'product_name' => "Product {$sku}",
        'variant_name' => "Variant {$sku}",
        ...$overrides,
    ];
}

/**
 * @param  list<string>  $headers
 * @param  list<array<string, int|string>>  $rows
 */
function writeCatalogWorkbook(string $path, array $headers, array $rows): void
{
    $directory = dirname($path);

    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues($headers));

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues(array_map(
            static fn (string $header): int|string => $row[$header] ?? '',
            $headers,
        )));
    }

    $writer->close();
}

/** @return list<string> */
function catalogWorkbookHeaders(string $path): array
{
    $reader = new Reader;
    $reader->open($path);

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $headers = array_map(
                static fn (Cell $cell): string => (string) $cell->getValue(),
                $row->getCells(),
            );
            $reader->close();

            return array_values($headers);
        }
    }

    $reader->close();

    return [];
}
