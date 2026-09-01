<?php

declare(strict_types=1);

use App\Enums\InventoryExportType;
use App\Enums\InventoryImportItemStatus;
use App\Enums\InventoryImportRunStatus;
use App\Enums\InventoryPermission;
use App\Enums\MovementType;
use App\Enums\StockCondition;
use App\Filament\Resources\InventoryExports\Schemas\InventoryExportRequestSchema;
use App\Filament\Resources\InventoryReports\Pages\ManageInventoryReports;
use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Filament\Widgets\InventoryStockValue;
use App\Models\AuditLog;
use App\Models\InventoryExport;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryExportService;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\AbstractWriter;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

it('creates a private asynchronous stock export with the requested filters and audit trail', function (): void {
    Storage::fake('local');
    (new InventoryPermissionSeeder)->run();
    $actor = User::factory()->create();
    $actor->givePermissionTo([
        InventoryPermission::Export->value,
        InventoryPermission::ReportView->value,
        InventoryPermission::StockView->value,
        InventoryPermission::PricingView->value,
    ]);

    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['cost_price' => 5]);
    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => 10,
        'reserved_quantity' => 2,
        'damaged_quantity' => 3,
        'available_quantity' => 5,
    ]);

    $export = app(InventoryExportService::class)->request(InventoryExportType::StockLevels->value, [
        'warehouse_id' => (string) $warehouse->getKey(),
        'unsupported' => 'discarded',
    ], $actor);
    $completed = $export->fresh();
    $sheets = exportWorkbook((string) $completed->file_path);

    expect($completed->status)->toBe('completed')
        ->and($completed->filters)->toBe(['warehouse_id' => $warehouse->getKey()])
        ->and(Storage::disk('local')->exists((string) $completed->file_path))->toBeTrue()
        ->and($sheets)->toHaveCount(1)
        ->and($sheets[0][0])->toContain('Damaged', 'Usable value')
        // Looked up by heading rather than by position, so adding a report column cannot
        // silently shift these assertions onto the wrong figures.
        ->and(exportCell($sheets[0], 'Damaged'))->toBe(3.0)
        ->and(exportCell($sheets[0], 'Available'))->toBe(5.0)
        ->and(exportCell($sheets[0], 'Usable value'))->toBe(25.0)
        ->and(AuditLog::query()->where('description', 'inventory.export.requested')->where('subject_id', $export->getKey())->exists())->toBeTrue();
});

it('exports enriched movement context with canonical condition and source filters', function (): void {
    Storage::fake('local');
    (new InventoryPermissionSeeder)->run();
    $actor = fullyAuthorizedExporter();
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();

    $matching = InventoryMovement::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'movement_type' => MovementType::Receipt,
        'quantity' => '2.000000',
        'transaction_quantity' => '2.000000',
        'transaction_unit_id' => $variant->unit_id,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity_delta' => '2.000000',
        'stock_condition_from' => StockCondition::Saleable,
        'stock_condition_to' => StockCondition::Saleable,
        'source_type' => 'inventory_operation',
        'source_id' => 901,
        'source_line_type' => 'inventory_operation_line',
        'source_line_id' => 902,
    ]);
    InventoryMovement::factory()->create([
        'movement_type' => MovementType::Adjustment,
        'source_type' => 'adjustment',
    ]);

    $export = app(InventoryExportService::class)->request(
        InventoryExportType::Movements->value,
        [
            'movement_type' => MovementType::Receipt->value,
            'stock_condition_from' => StockCondition::Saleable->value,
            'source_type' => 'inventory_operation',
        ],
        $actor,
    )->fresh();
    $rows = exportWorkbook((string) $export->file_path)[0];
    $movementExportFields = collect(InventoryExportRequestSchema::make(InventoryExportType::Movements))
        ->map(fn (\Filament\Schemas\Components\Component $component): string => $component->getName())
        ->all();

    expect($movementExportFields)->toContain(
        'warehouse_id',
        'product_variant_id',
        'movement_type',
        'stock_condition_from',
        'stock_condition_to',
        'source_type',
        'from',
        'until',
    )
        ->and($export->filters)->toBe([
        'movement_type' => MovementType::Receipt->value,
        'stock_condition_from' => StockCondition::Saleable->value,
        'source_type' => 'inventory_operation',
    ])
        ->and($rows)->toHaveCount(2)
        ->and($rows[0])->toContain(
            'Transaction quantity',
            'Transaction unit',
            'Base quantity delta',
            'Condition from',
            'Condition to',
            'Source line type',
            'Reversal movement ID',
        )
        ->and(exportCell($rows, 'Base quantity delta'))->toBe(2.0)
        ->and(exportValue($rows, 'Source type'))->toBe('inventory_operation')
        ->and($matching->refresh()->quantity)->toBe('2.000000');
});

it('generates every supported workbook type including composite report sheets', function (): void {
    Storage::fake('local');
    (new InventoryPermissionSeeder)->run();
    $actor = fullyAuthorizedExporter();

    foreach (InventoryExportType::cases() as $type) {
        $export = app(InventoryExportService::class)->request($type->value, [], $actor)->fresh();
        $sheets = exportWorkbook((string) $export->file_path);
        $expectedSheets = match ($type) {
            InventoryExportType::PricingTiers => 3,
            InventoryExportType::ImportResults => 2,
            default => 1,
        };

        expect($export->status)->toBe('completed')
            ->and(Storage::disk('local')->exists((string) $export->file_path))->toBeTrue()
            ->and($sheets)->toHaveCount($expectedSheets)
            ->and($sheets[0][0])->not->toBeEmpty();
    }
});

it('keeps import run and row status filters independent in the composite workbook', function (): void {
    Storage::fake('local');
    (new InventoryPermissionSeeder)->run();
    $actor = fullyAuthorizedExporter();

    $export = app(InventoryExportService::class)->request(
        InventoryExportType::ImportResults->value,
        [
            'run_status' => InventoryImportRunStatus::Confirmed->value,
            'item_status' => InventoryImportItemStatus::Applied->value,
        ],
        $actor,
    )->fresh();

    expect($export->filters)->toBe([
        'run_status' => InventoryImportRunStatus::Confirmed->value,
        'item_status' => InventoryImportItemStatus::Applied->value,
    ])->and(exportWorkbook((string) $export->file_path))->toHaveCount(2);
});

it('keeps supplier prices in their original currencies without conversion', function (): void {
    Storage::fake('local');
    (new InventoryPermissionSeeder)->run();
    $actor = fullyAuthorizedExporter();
    $supplier = Supplier::factory()->create(['name' => 'Currency supplier', 'code' => 'CUR-SUP']);
    $variant = ProductVariant::factory()->create();
    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'supplier_name' => $supplier->name,
        'supplier_item_number' => 'CUR-ITEM',
        'country_code' => 'TR',
        'purchase_cost' => 123.45,
        'currency_code' => 'TRY',
        'is_active' => true,
    ]);

    $export = app(InventoryExportService::class)->request(
        InventoryExportType::SupplierComparison->value,
        ['country_code' => 'tr'],
        $actor,
    )->fresh();
    $rows = exportWorkbook((string) $export->file_path)[0];

    expect($rows)->toHaveCount(2)
        ->and((float) $rows[1][7])->toBe(123.45)
        ->and($rows[1][8])->toBe('TRY');
});

it('values only usable stock in the warehouse stock-value widget', function (): void {
    (new InventoryPermissionSeeder)->run();
    $warehouse = Warehouse::factory()->create(['name' => 'Usable value warehouse']);
    $variant = ProductVariant::factory()->create(['cost_price' => 5]);
    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => 10,
        'reserved_quantity' => 2,
        'damaged_quantity' => 3,
        'available_quantity' => 5,
    ]);
    $widget = app(InventoryStockValue::class);
    /** @var array{labels: list<string>, datasets: list<array{data: list<float>}>} $data */
    $data = new ReflectionMethod($widget, 'getData')->invoke($widget);
    $stockViewer = User::factory()->create();
    $stockViewer->givePermissionTo(InventoryPermission::StockView->value);
    $this->actingAs($stockViewer);

    expect($data['labels'])->toBe(['Usable value warehouse'])
        ->and($data['datasets'][0]['data'])->toBe([25.0])
        ->and(InventoryStockValue::canView())->toBeFalse();

    $stockViewer->givePermissionTo(InventoryPermission::PricingView->value);
    $stockViewer->refresh();

    expect(InventoryStockValue::canView())->toBeTrue();
});

it('shows export request actions only for reports the administrator may view', function (): void {
    Storage::fake('local');
    (new InventoryPermissionSeeder)->run();
    $actor = User::factory()->create();
    $actor->givePermissionTo([
        InventoryPermission::Export->value,
        InventoryPermission::ReportView->value,
        InventoryPermission::StockView->value,
    ]);

    Livewire::actingAs($actor)
        ->test(ListStockLevels::class)
        ->assertActionVisible('request_stock_levels')
        ->callAction('request_stock_levels', data: [])
        ->assertHasNoActionErrors();

    Livewire::actingAs($actor)
        ->test(ManageInventoryReports::class)
        ->assertActionHidden('request_supplier_comparison')
        ->assertActionHidden('request_price_history');
});

it('omits optional pricing fields and rechecks permissions for generation and download', function (): void {
    Storage::fake('local');
    Queue::fake();
    (new InventoryPermissionSeeder)->run();
    $actor = User::factory()->create();
    $actor->givePermissionTo([
        InventoryPermission::Export->value,
        InventoryPermission::ReportView->value,
        InventoryPermission::CatalogView->value,
        InventoryPermission::StockView->value,
    ]);
    ProductVariant::factory()->create(['cost_price' => 99, 'base_price' => 120]);

    $catalogExport = app(InventoryExportService::class)->request(InventoryExportType::Catalog->value, [], $actor);
    app(InventoryExportService::class)->generate($catalogExport);
    $headings = exportWorkbook((string) $catalogExport->fresh()->file_path)[0][0];

    expect($headings)->not->toContain('Cost', 'Base price', 'Minimum price');
    expect(fn () => app(InventoryExportService::class)->request(
        InventoryExportType::SupplierComparison->value,
        [],
        $actor,
    ))->toThrow(DomainException::class);

    $stockExport = app(InventoryExportService::class)->request(InventoryExportType::StockLevels->value, [], $actor);
    $actor->revokePermissionTo(InventoryPermission::StockView->value);

    expect(fn () => app(InventoryExportService::class)->generate($stockExport))
        ->toThrow(DomainException::class);

    $downloadOwner = User::factory()->create();
    $downloadOwner->givePermissionTo([
        InventoryPermission::Export->value,
        InventoryPermission::ReportView->value,
        InventoryPermission::StockView->value,
    ]);
    $downloadExport = app(InventoryExportService::class)->request(
        InventoryExportType::StockLevels->value,
        [],
        $downloadOwner,
    );
    app(InventoryExportService::class)->generate($downloadExport);
    $unauthorizedDownloader = User::factory()->create();
    $unauthorizedDownloader->givePermissionTo([
        InventoryPermission::Export->value,
        InventoryPermission::ReportView->value,
    ]);

    expect(fn () => app(InventoryExportService::class)->download($downloadExport->fresh(), $unauthorizedDownloader))
        ->toThrow(DomainException::class);
});

it('rejects invalid export requests and exports without their originating administrator', function (): void {
    (new InventoryPermissionSeeder)->run();
    $service = app(InventoryExportService::class);

    expect(fn () => $service->request('unsupported-export', [], User::factory()->create()))
        ->toThrow(DomainException::class);
    expect(fn () => $service->request(
        InventoryExportType::StockLevels->value,
        [],
        User::factory()->create(),
    ))->toThrow(DomainException::class, __('admin.inventory.export.errors.unauthorized'));

    $orphaned = InventoryExport::query()->create([
        'type' => InventoryExportType::StockLevels->value,
        'filters' => [],
        'status' => 'queued',
        'created_by' => null,
    ]);

    expect(fn () => $service->generate($orphaned))
        ->toThrow(DomainException::class);

    $invalid = InventoryExport::query()->create([
        'type' => 'legacy-invalid-type',
        'filters' => [],
        'status' => 'queued',
        'created_by' => null,
    ]);

    expect(fn () => $service->generate($invalid))
        ->toThrow(DomainException::class);
});

it('marks a generation failure and removes an incomplete private export', function (): void {
    Storage::fake('local');
    Queue::fake();
    (new InventoryPermissionSeeder)->run();
    $actor = fullyAuthorizedExporter();
    $service = app(InventoryExportService::class);
    $export = $service->request(InventoryExportType::StockLevels->value, [], $actor);

    Storage::disk('local')->put('inventory-exports', 'blocks the required directory');

    expect(fn () => $service->generate($export))->toThrow(LogicException::class);

    expect($export->fresh()->status)->toBe('failed')
        ->and($export->fresh()->failure_reason)->toBe('Unable to create the private export directory.')
        ->and(Storage::disk('local')->exists(sprintf('inventory-exports/%s.xlsx', $export->getKey())))->toBeFalse();
});

it('rejects unfinished downloads and returns completed private files', function (): void {
    Storage::fake('local');
    Queue::fake();
    (new InventoryPermissionSeeder)->run();
    $actor = fullyAuthorizedExporter();
    $service = app(InventoryExportService::class);
    $export = $service->request(InventoryExportType::StockLevels->value, [], $actor);

    expect(fn () => $service->download($export, $actor))
        ->toThrow(DomainException::class);

    $service->generate($export);
    $response = $service->download($export->fresh(), $actor);

    expect($response->getFile()->getPathname())
        ->toBe(Storage::disk('local')->path((string) $export->fresh()->file_path))
        ->and($response->headers->get('content-disposition'))
        ->toContain(sprintf('stock_levels-%s.xlsx', $export->getKey()));
});

it('handles legacy filter payloads and guards internal integer identifiers', function (): void {
    Storage::fake('local');
    Queue::fake();
    (new InventoryPermissionSeeder)->run();
    $actor = fullyAuthorizedExporter();
    $service = app(InventoryExportService::class);
    $export = InventoryExport::query()->create([
        'type' => InventoryExportType::Catalog->value,
        'filters' => null,
        'status' => 'queued',
        'created_by' => $actor->getKey(),
    ]);

    $service->generate($export);

    expect($export->fresh()->status)->toBe('completed');

    $unsaved = new InventoryExport([
        'type' => InventoryExportType::Catalog->value,
        'filters' => [],
        'status' => 'queued',
    ]);
    $idMethod = new ReflectionMethod($service, 'exportId');

    expect(fn (): mixed => $idMethod->invoke($service, $unsaved))
        ->toThrow(LogicException::class, 'Inventory exports must use integer identifiers.');

    new ReflectionMethod($service, 'closeAfterFailure')->invoke($service, new Writer);
});

it('reports a writer close failure without replacing the export failure', function (): void {
    Storage::fake('local');
    $service = app(InventoryExportService::class);
    $writer = new Writer;
    $writer->openToFile(Storage::disk('local')->path('corrupted-close.xlsx'));

    $filePointer = new ReflectionProperty(AbstractWriter::class, 'filePointer');
    $pointer = $filePointer->getValue($writer);

    if (! is_resource($pointer)) {
        throw new RuntimeException('Expected an opened export file pointer.');
    }

    fclose($pointer);
    new ReflectionMethod($service, 'closeAfterFailure')->invoke($service, $writer);
    new ReflectionProperty(AbstractWriter::class, 'isWriterOpened')->setValue($writer, false);

    expect(true)->toBeTrue();
});

it('guards export request visibility for unauthenticated callbacks', function (): void {
    (new InventoryPermissionSeeder)->run();
    $page = Livewire::actingAs(fullyAuthorizedExporter())->test(ListStockLevels::class)->instance();
    $canRequest = new ReflectionMethod($page, 'canRequestInventoryExport');

    auth()->logout();

    expect($canRequest->invoke($page, InventoryExportType::Catalog))->toBeFalse();
});

/**
 * The value of one named column on a sheet's first data row.
 *
 * @param  array<int, array<int, mixed>>  $sheet  header row first, then data rows
 */
function exportCell(array $sheet, string $heading, int $dataRow = 1): float
{
    return (float) exportValue($sheet, $heading, $dataRow);
}

/** @param array<int, array<int, mixed>> $sheet */
function exportValue(array $sheet, string $heading, int $dataRow = 1): mixed
{
    $column = array_search($heading, $sheet[0], true);

    if (! is_int($column)) {
        throw new LogicException(sprintf('The export sheet has no [%s] column.', $heading));
    }

    return $sheet[$dataRow][$column];
}

/** @return list<list<list<mixed>>> */
function exportWorkbook(string $path): array
{
    $reader = new Reader;
    $reader->open(Storage::disk('local')->path($path));

    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = [];

        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }

        $sheets[] = $rows;
    }

    $reader->close();

    return $sheets;
}

function fullyAuthorizedExporter(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo([
        InventoryPermission::Export->value,
        InventoryPermission::ReportView->value,
        InventoryPermission::CatalogView->value,
        InventoryPermission::StockView->value,
        InventoryPermission::MovementView->value,
        InventoryPermission::PricingView->value,
        InventoryPermission::ImportManage->value,
    ]);

    return $actor;
}
