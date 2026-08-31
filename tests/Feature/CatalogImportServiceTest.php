<?php

declare(strict_types=1);

use App\Data\Inventory\InventoryImportRowResult;
use App\Enums\InventoryImportItemStatus;
use App\Enums\InventoryImportRunStatus;
use App\Enums\SerializedInventoryUnitStatus;
use App\Jobs\ApplyCatalogImport;
use App\Jobs\ParseCatalogImport;
use App\Models\InventoryImportItem;
use App\Models\InventoryImportRun;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttributeValue;
use App\Models\SerializedInventoryUnit;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\CatalogImportApplicationService;
use App\Services\Inventory\CatalogImportCatalogService;
use App\Services\Inventory\CatalogImportReportService;
use App\Services\Inventory\CatalogImportService;
use App\Services\Inventory\CatalogImportValidator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
    $existingVariant = ProductVariant::factory()->machine()->create();
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

it('begins only XLSX uploads and rejects missing or invalid stored files', function (): void {
    Queue::fake();
    $actor = User::factory()->create();
    $service = app(CatalogImportService::class);

    expect(fn () => $service->begin(UploadedFile::fake()->create('catalog.csv'), $actor))
        ->toThrow(DomainException::class, __('admin.inventory.import.errors.file_type'))
        ->and(fn () => $service->queueStoredFile('catalog-imports/missing.xlsx', $actor))
        ->toThrow(DomainException::class, __('admin.inventory.import.errors.store_failed'))
        ->and(fn () => $service->queueStoredFile('elsewhere/file.xlsx', $actor))
        ->toThrow(DomainException::class, __('admin.inventory.import.errors.store_failed'));

    $run = $service->begin(
        UploadedFile::fake()->createWithContent('catalog.xlsx', 'queued workbook'),
        $actor,
    );

    expect($run->status)->toBe(InventoryImportRunStatus::Queued)
        ->and(Storage::disk('local')->exists($run->file_path))->toBeTrue();

    $unstorable = Mockery::mock(UploadedFile::class);
    $unstorable->shouldReceive('getClientOriginalExtension')->once()->andReturn('xlsx');
    $unstorable->shouldReceive('store')->once()->andReturn(false);

    expect(fn () => $service->begin($unstorable, $actor))
        ->toThrow(DomainException::class, __('admin.inventory.import.errors.store_failed'));
});

it('validates template scalar inventory identity expiry and attribute boundaries', function (): void {
    $validator = app(CatalogImportValidator::class);

    expect(fn () => $validator->assertRequiredColumns(['sku', 'product_name']))
        ->toThrow(DomainException::class, __('admin.inventory.import.errors.invalid_template'))
        ->and(fn () => $validator->assertRequiredColumns(['sku', 'product_name', 'variant_name', 'sku']))
        ->toThrow(DomainException::class, __('admin.inventory.import.errors.invalid_template'));

    $errors = $validator->validate([
        'sku' => '',
        'product_name' => '',
        'variant_name' => '',
        'cost_price' => 'not-numeric',
        'product_status' => 'not-a-status',
        'lot_number' => 'LOT-REQUIRES-INVENTORY',
        'track_serials' => 'true',
        'track_expiry' => 'true',
        'attribute_unknown' => 'value',
    ], $validator->activeAttributes());

    expect($errors)->toHaveKeys([
        'sku',
        'product_name',
        'variant_name',
        'cost_price',
        'product_status',
        'warehouse_code',
        'quantity',
        'serial_number',
        'expires_at',
        'attribute_unknown',
    ]);

    $existingUnit = SerializedInventoryUnit::factory()->create([
        'serial_number' => 'DUPLICATE-VALIDATOR-SERIAL',
        'iot_number' => 'DUPLICATE-VALIDATOR-IOT',
    ]);
    $errors = $validator->validate([
        'sku' => 'VALIDATOR-SKU',
        'product_name' => 'Validator product',
        'variant_name' => 'Validator variant',
        'warehouse_code' => 'UNKNOWN',
        'quantity' => '2',
        'serial_number' => $existingUnit->serial_number,
        'iot_number' => (string) $existingUnit->iot_number,
        'expires_at' => 'not-a-date',
        'attribute_color' => 'Missing select value',
    ], $validator->activeAttributes());

    expect($errors['warehouse_code'])->toContain('unknown_or_inactive')
        ->and($errors['quantity'])->toContain('serialized_quantity_must_be_one')
        ->and($errors['serial_number'])->toContain('duplicate')
        ->and($errors['iot_number'])->toContain('duplicate')
        ->and($errors['expires_at'])->toContain('date')
        ->and($errors['attribute_color'])->toContain('unknown_or_inactive_value');

    $missingWarehouse = $validator->validate([
        'sku' => 'QUANTITY-ONLY',
        'product_name' => 'Quantity product',
        'variant_name' => 'Quantity variant',
        'quantity' => '-1',
    ], $validator->activeAttributes());

    expect($missingWarehouse['quantity'])->toContain('positive')
        ->and($validator->tracksSerials(['track_serials' => 'false']))->toBeFalse()
        ->and($validator->tracksExpiry(['track_expiry' => 'false']))->toBeFalse()
        ->and($validator->tracksSerials([]))->toBeFalse()
        ->and($validator->tracksExpiry([]))->toBeFalse();

    // One variant per tracking mode: a product's type fixes its tracking, so no single
    // variant can both carry serials and expire.
    $machineVariant = ProductVariant::factory()->machine()->create();
    $expiringVariant = ProductVariant::factory()->expiryMaterial()->create();

    expect($validator->tracksSerials(['sku' => $machineVariant->sku]))->toBeTrue()
        ->and($validator->tracksExpiry(['sku' => $machineVariant->sku]))->toBeFalse()
        ->and($validator->tracksExpiry(['sku' => $expiringVariant->sku]))->toBeTrue()
        ->and($validator->tracksSerials(['sku' => $expiringVariant->sku]))->toBeFalse()
        ->and($validator->hasInventoryData([]))->toBeFalse()
        ->and($validator->hasInventoryData(['quantity' => '1']))->toBeTrue();
});

it('persists every optional catalog field and replaces attribute assignments', function (): void {
    $actor = User::factory()->create();
    $service = app(CatalogImportCatalogService::class);
    [$variant, $result] = $service->apply([
        'sku' => 'OPTIONAL-SKU',
        'product_name' => 'Optional product',
        'product_name_ar' => 'Optional Arabic product',
        'variant_name' => 'Optional variant',
        'variant_name_ar' => 'Optional Arabic variant',
        'product_status' => 'active',
        'brand_code' => 'OPTIONAL-BRAND',
        'brand_name' => 'Optional brand',
        'category_name' => 'Child category',
        'parent_category_name' => 'Parent category',
        'unit_symbol' => 'BOX',
        'unit_name' => 'Box',
        'allows_decimal' => 'true',
        'barcode' => 'OPTIONAL-BARCODE',
        'cost_price' => '20',
        'markup_percent' => '25',
        'min_price' => '22',
        'supplier_code' => 'OPTIONAL-SUPPLIER',
        'supplier_name' => 'Optional supplier',
        'supplier_item_number' => 'SUPPLIER-ITEM',
        'country_code' => 'SY',
        'manufacturer' => 'Optional manufacturer',
        'currency_code' => 'EUR',
        'attribute_color' => 'Red',
        'attribute_material' => 'Bronze',
    ], $actor);

    $reference = SupplierProductReference::query()->sole();
    $materialValue = ProductAttributeValue::query()->where('value', 'Bronze')->sole();

    expect($result->catalogOperation)->toBe('catalog_created')
        ->and($variant->product?->brand?->code)->toBe('OPTIONAL-BRAND')
        ->and($variant->product?->category?->parent?->name)->toBe('Parent category')
        ->and($variant->unit?->symbol)->toBe('BOX')
        ->and($variant->unit?->allows_decimal)->toBeTrue()
        ->and($variant->fresh()->base_price)->toBe('25.00')
        ->and($reference->supplier?->code)->toBe('OPTIONAL-SUPPLIER')
        ->and($reference->currency_code)->toBe('EUR')
        ->and($materialValue->variantAssignments()->where('product_variant_id', $variant->getKey())->exists())->toBeTrue();

    $service->apply([
        'sku' => 'OPTIONAL-SKU',
        'product_name' => 'Optional product',
        'variant_name' => 'Optional variant updated',
        'attribute_unknown' => 'ignored by the catalog application boundary',
        'attribute_material' => 'Steel',
    ], $actor);

    expect(ProductVariantAttributeValue::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('product_attribute_value_id', $materialValue->getKey())
        ->exists())->toBeFalse()
        ->and($variant->fresh()->name)->toBe('Optional variant updated');

    expect(fn () => $service->apply([
        'sku' => 'MISSING-SELECT-SKU',
        'product_name' => 'Missing select product',
        'variant_name' => 'Missing select variant',
        'unit_symbol' => 'EA',
        'attribute_color' => 'No longer active',
    ], $actor))->toThrow(DomainException::class, 'Attribute color no longer has the selected active value.');

    expect($service->resolveSupplier([]))->toBeNull()
        ->and($service->resolveSupplier(['supplier_name' => 'Name only supplier']))->toBeInstanceOf(Supplier::class);

    [$categoryVariant] = $service->apply([
        'sku' => 'CATEGORY-WITHOUT-PARENT',
        'product_name' => 'Category product',
        'variant_name' => 'Category variant',
        'unit_symbol' => 'EA',
        'category_name' => 'Standalone category',
    ], $actor);
    $floatOrNull = new ReflectionMethod($service, 'floatOrNull');

    expect($categoryVariant->product?->category?->parent_id)->toBeNull()
        ->and($floatOrNull->invoke($service, '12.5'))->toBe(12.5)
        ->and($floatOrNull->invoke($service, 'not-numeric'))->toBeNull();
});

it('marks parse failures invalid states and confirmed failures without partial rows', function (): void {
    $actor = User::factory()->create();
    $service = app(CatalogImportService::class);
    $path = 'catalog-imports/invalid-template.xlsx';
    writeCatalogWorkbook(Storage::disk('local')->path($path), ['sku'], [['sku' => 'ONLY-SKU']]);
    $run = InventoryImportRun::factory()->create([
        'file_path' => $path,
        'created_by' => $actor->getKey(),
    ]);

    expect(fn () => $service->parse($run))
        ->toThrow(DomainException::class, __('admin.inventory.import.errors.invalid_template'));

    expect($run->fresh()->status)->toBe(InventoryImportRunStatus::Failed)
        ->and($run->items()->count())->toBe(0)
        ->and(fn () => $service->parse($run->fresh()))
        ->toThrow(DomainException::class, __('admin.inventory.import.errors.invalid_state'));

    $confirmed = InventoryImportRun::factory()->create([
        'status' => InventoryImportRunStatus::Confirmed,
    ]);
    $service->markFailed($confirmed, new RuntimeException('must not replace a confirmed state'));

    expect($confirmed->fresh()->status)->toBe(InventoryImportRunStatus::Confirmed);
});

it('parses blank rows and every supported spreadsheet cell value', function (): void {
    $service = app(CatalogImportService::class);
    $path = 'catalog-imports/cell-values.xlsx';
    writeCatalogWorkbook(Storage::disk('local')->path($path), catalogImportHeaders(), [
        array_fill_keys(catalogImportHeaders(), ' '),
        catalogImportRow('CELL-SKU'),
    ]);
    $run = InventoryImportRun::factory()->create(['file_path' => $path]);
    $service->parse($run);
    $cellValue = new ReflectionMethod($service, 'cellValue');
    $rowPayload = new ReflectionMethod($service, 'rowPayload');
    $isBlankRow = new ReflectionMethod($service, 'isBlankRow');

    expect($run->fresh()->total_rows)->toBe(1)
        ->and($run->fresh()->status)->toBe(InventoryImportRunStatus::Ready)
        ->and($cellValue->invoke($service, new DateTimeImmutable('2026-01-02')))->toBe('2026-01-02')
        ->and($cellValue->invoke($service, true))->toBe('true')
        ->and($cellValue->invoke($service, null))->toBe('')
        ->and($isBlankRow->invoke($service, ['', '']))->toBeTrue()
        ->and($rowPayload->invoke($service, ['sku', 'missing'], ['ROW-SKU']))->toBe(['sku' => 'ROW-SKU']);
});

it('isolates catalog race failures and handles idempotent application branches', function (): void {
    Queue::fake();
    $actor = User::factory()->create();
    $service = app(CatalogImportService::class);
    $path = 'catalog-imports/catalog-race.xlsx';
    writeCatalogWorkbook(Storage::disk('local')->path($path), catalogImportHeaders(), [
        catalogImportRow('RACE-SKU', ['attribute_color' => 'Red']),
    ]);
    $run = InventoryImportRun::factory()->create([
        'file_path' => $path,
        'created_by' => $actor->getKey(),
    ]);
    $service->parse($run);
    ProductAttributeValue::query()->where('value', 'Red')->update(['is_active' => false]);
    $service->confirm($run, $actor);
    $service->apply($run, $actor);

    expect($run->fresh()->status)->toBe(InventoryImportRunStatus::ConfirmedWithErrors)
        ->and($run->items()->sole()->status)->toBe(InventoryImportItemStatus::Rejected)
        ->and($run->items()->sole()->runtime_error)->toContain('no longer has the selected active value');

    $application = app(CatalogImportApplicationService::class);
    $appliedItem = $run->items()->sole();
    new ReflectionMethod($application, 'applyCatalogItem')->invoke($application, $appliedItem, $actor);
    new ReflectionMethod($application, 'applyInventoryGroup')->invoke(
        $application,
        new Collection([$appliedItem]),
        $actor,
    );
    new ReflectionMethod($application, 'finishRun')->invoke($application, $run->fresh(), $actor);
    new ReflectionMethod($application, 'completeInventoryResult')->invoke(
        $application,
        new InventoryImportRowResult,
    );

    expect($run->fresh()->status)->toBe(InventoryImportRunStatus::ConfirmedWithErrors);
});

it('records report-generation failures without undoing a confirmed import', function (): void {
    Queue::fake();
    $actor = User::factory()->create();
    $service = app(CatalogImportService::class);
    $path = 'catalog-imports/report-failure.xlsx';
    writeCatalogWorkbook(Storage::disk('local')->path($path), catalogImportHeaders(), [
        catalogImportRow('REPORT-FAILURE-SKU'),
    ]);
    $run = InventoryImportRun::factory()->create([
        'file_path' => $path,
        'created_by' => $actor->getKey(),
    ]);
    $service->parse($run);
    $service->confirm($run, $actor);
    Storage::disk('local')->put('catalog-imports/results', 'blocks the result directory');
    $service->apply($run, $actor);

    expect($run->fresh()->status)->toBe(InventoryImportRunStatus::Confirmed)
        ->and($run->fresh()->failure_message)->toContain('Report generation failed:')
        ->and($run->fresh()->result_path)->toBeNull()
        ->and($run->fresh()->summary_path)->toBeNull();
});

it('executes parse and apply jobs and reconciles their terminal failures', function (): void {
    $actor = User::factory()->create();
    $service = app(CatalogImportService::class);
    $path = 'catalog-imports/job.xlsx';
    writeCatalogWorkbook(Storage::disk('local')->path($path), catalogImportHeaders(), [
        catalogImportRow('JOB-SKU'),
    ]);
    $parseRun = InventoryImportRun::factory()->create([
        'file_path' => $path,
        'created_by' => $actor->getKey(),
    ]);
    $parseJob = new ParseCatalogImport($parseRun->getKey());
    $parseJob->handle($service);
    $parseJob->failed(null);
    new ParseCatalogImport(PHP_INT_MAX)->failed(new RuntimeException('missing run'));

    expect($parseRun->fresh()->status)->toBe(InventoryImportRunStatus::Ready);

    $parseFailure = InventoryImportRun::factory()->create([
        'status' => InventoryImportRunStatus::Parsing,
        'created_by' => $actor->getKey(),
    ]);
    new ParseCatalogImport($parseFailure->getKey())
        ->failed(new RuntimeException('parse queue failure'));

    expect($parseFailure->fresh()->status)->toBe(InventoryImportRunStatus::Failed);

    $applyRun = InventoryImportRun::factory()->create([
        'status' => InventoryImportRunStatus::Applying,
        'created_by' => $actor->getKey(),
        'confirmed_by' => $actor->getKey(),
    ]);
    $applyJob = new ApplyCatalogImport($applyRun->getKey(), $actor->getKey());
    $applyJob->handle($service);
    $applyJob->failed(null);
    new ApplyCatalogImport(PHP_INT_MAX, $actor->getKey())->failed(new RuntimeException('missing run'));

    expect($applyRun->fresh()->status)->toBe(InventoryImportRunStatus::Confirmed);

    $failedRun = InventoryImportRun::factory()->create([
        'status' => InventoryImportRunStatus::Applying,
        'created_by' => $actor->getKey(),
    ]);
    new ApplyCatalogImport($failedRun->getKey(), $actor->getKey())
        ->failed(new RuntimeException('queue failure'));

    expect($failedRun->fresh()->status)->toBe(InventoryImportRunStatus::Failed)
        ->and($failedRun->fresh()->failure_message)->toBe('queue failure');
});

it('marks an unexpected application failure and skips reports for unfinished runs', function (): void {
    $actor = User::factory()->create();
    $service = app(CatalogImportService::class);
    $run = InventoryImportRun::factory()->create([
        'status' => InventoryImportRunStatus::Applying,
        'created_by' => $actor->getKey(),
        'confirmed_by' => $actor->getKey(),
    ]);
    $item = InventoryImportItem::factory()->for($run, 'run')->create([
        'status' => InventoryImportItemStatus::Valid,
    ]);
    DB::table('inventory_import_items')
        ->where('id', $item->getKey())
        ->update(['payload' => '"legacy-string-payload"']);

    expect(fn () => $service->apply($run, $actor))->toThrow(TypeError::class);

    expect($run->fresh()->status)->toBe(InventoryImportRunStatus::Failed);

    $queued = InventoryImportRun::factory()->create([
        'status' => InventoryImportRunStatus::Queued,
        'created_by' => $actor->getKey(),
    ]);
    $service->apply($queued, $actor);

    expect($queued->fresh()->status)->toBe(InventoryImportRunStatus::Queued);
});

it('guards import report streams json values and integer identifiers', function (): void {
    $service = app(CatalogImportReportService::class);
    $json = new ReflectionMethod($service, 'json');
    $scalar = new ReflectionMethod($service, 'scalarValue');
    $integerKey = new ReflectionMethod($service, 'integerKey');
    $temporaryStream = new ReflectionMethod($service, 'temporaryStream');

    $handle = fopen('php://memory', 'rb');

    try {
        expect(fn (): mixed => $json->invoke($service, $handle))
            ->toThrow(LogicException::class, 'Unable to encode an import report value.')
            ->and($scalar->invoke($service, ['nested' => true]))->toBe('{"nested":true}')
            ->and(fn (): mixed => $integerKey->invoke($service, null))
            ->toThrow(LogicException::class, 'Inventory import runs must use integer identifiers.');
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
    }

    stream_wrapper_unregister('php');

    try {
        expect(fn (): mixed => $temporaryStream->invoke($service))
            ->toThrow(LogicException::class, 'Unable to create the import report stream.');
    } finally {
        stream_wrapper_restore('php');
    }
});

it('guards unsaved import run item and actor identifiers', function (): void {
    $service = app(CatalogImportService::class);
    $application = app(CatalogImportApplicationService::class);
    $importRunId = new ReflectionMethod($service, 'importRunId');
    $userId = new ReflectionMethod($service, 'userId');
    $integerKey = new ReflectionMethod($application, 'integerKey');

    expect(fn (): mixed => $importRunId->invoke($service, new InventoryImportRun))
        ->toThrow(LogicException::class, 'Inventory import runs must use integer identifiers.')
        ->and(fn (): mixed => $userId->invoke($service, new User))
        ->toThrow(LogicException::class, 'Inventory import actors must use integer identifiers.')
        ->and(fn (): mixed => $integerKey->invoke($application, null))
        ->toThrow(LogicException::class, 'Imported inventory entities must use integer identifiers.');
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
        'unit_symbol',
        'unit_name',
        'allows_decimal',
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
        'product_name' => 'Product '.$sku,
        'variant_name' => 'Variant '.$sku,
        'unit_symbol' => 'EA',
        'unit_name' => 'Each',
        'allows_decimal' => 'false',
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
