<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Jobs\ParseCatalogImport;
use App\Models\Brand;
use App\Models\InventoryImportItem;
use App\Models\InventoryImportRun;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use DateTimeInterface;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

final readonly class CatalogImportService
{
    /** @var list<string> */
    private const array COLUMNS = [
        'sku', 'product_name', 'product_name_ar', 'variant_name', 'variant_name_ar', 'product_status',
        'brand_code', 'brand_name', 'category_name', 'parent_category_name', 'unit_symbol', 'unit_name',
        'allows_decimal', 'barcode', 'track_serials', 'track_expiry', 'cost_price', 'base_price', 'min_price',
        'markup_percent', 'supplier_code', 'supplier_name', 'supplier_item_number', 'country_code', 'manufacturer',
        'currency_code', 'serial_number', 'iot_number', 'lot_number', 'expires_at',
    ];

    public function __construct(
        private AuditLogger $auditLogger,
        private PriceResolver $priceResolver,
    ) {}

    /** @throws DomainException */
    public function begin(UploadedFile $file, User $actor): InventoryImportRun
    {
        if (mb_strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
            throw new DomainException(__('admin.inventory.import.errors.file_type'));
        }

        $path = $file->store('catalog-imports', 'local');

        if (! is_string($path)) {
            throw new DomainException(__('admin.inventory.import.errors.store_failed'));
        }

        return $this->queueStoredFile($path, $actor);
    }

    public function queueStoredFile(string $path, User $actor): InventoryImportRun
    {
        $run = InventoryImportRun::query()->create([
            'file_path' => $path,
            'status' => 'queued',
            'created_by' => $actor->getKey(),
        ]);

        ParseCatalogImport::dispatch($this->importRunId($run));

        return $run;
    }

    public function writeTemplate(string $path): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(self::COLUMNS));
        $writer->addRow(Row::fromValues([
            'SKU-001', 'Product English', 'اسم المنتج', 'Variant English', 'اسم الصنف', 'active',
            'BRAND', 'Brand name', 'Category', '', 'EA', 'Each', 'false', '123456789', 'false', 'false',
            '10.00', '12.50', '11.00', '25.00', 'SUP', 'Supplier name', 'SUP-ITEM-001', 'SY', 'Manufacturer',
            'USD', '', '', '', '',
        ]));
        $writer->close();
    }

    public function parse(InventoryImportRun $run): void
    {
        $run->forceFill(['status' => 'parsing'])->save();
        $run->items()->delete();
        $reader = new Reader;

        try {
            $reader->open(Storage::disk('local')->path($run->file_path));
            $header = null;
            $rowNumber = 0;
            $totalRows = 0;
            $validRows = 0;
            $failedRows = 0;

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rowNumber++;
                    $values = array_values(array_map(fn (Cell $cell): string => $this->cellValue($cell->getValue()), $row->getCells()));

                    if ($header === null) {
                        $header = array_map(fn (string $value): string => Str::snake(mb_strtolower(mb_trim($value))), $values);
                        $this->assertTemplateColumns($header);

                        continue;
                    }

                    if ($this->isBlankRow($values)) {
                        continue;
                    }

                    $totalRows++;
                    $payload = $this->rowPayload($header, $values);
                    $errors = $this->validateRow($payload);
                    $isValid = $errors === [];
                    $validRows += $isValid ? 1 : 0;
                    $failedRows += $isValid ? 0 : 1;

                    $run->items()->create([
                        'row_number' => $rowNumber,
                        'payload' => $payload,
                        'errors' => $isValid ? null : $errors,
                        'status' => $isValid ? 'valid' : 'invalid',
                    ]);
                }

                break;
            }

            $run->forceFill([
                'status' => $failedRows === 0 ? 'ready' : 'invalid',
                'total_rows' => $totalRows,
                'valid_rows' => $validRows,
                'failed_rows' => $failedRows,
            ])->save();
        } catch (Throwable $throwable) {
            $run->forceFill(['status' => 'failed'])->save();
            throw $throwable;
        } finally {
            $reader->close();
        }
    }

    /** @throws DomainException */
    public function confirm(InventoryImportRun $run, User $actor): void
    {
        DB::transaction(function () use ($run, $actor): void {
            /** @var InventoryImportRun $locked */
            $locked = InventoryImportRun::query()->lockForUpdate()->findOrFail($run->getKey());

            if ($locked->status !== 'ready' || $locked->failed_rows > 0) {
                throw new DomainException(__('admin.inventory.import.errors.not_ready'));
            }

            foreach ($locked->items()->where('status', 'valid')->orderBy('row_number')->lockForUpdate()->get() as $item) {
                $this->applyRow($item, $actor);
                $item->forceFill(['status' => 'applied', 'applied_at' => now()])->save();
            }

            $locked->forceFill(['status' => 'confirmed', 'confirmed_at' => now()])->save();
            $this->auditLogger->log(
                action: 'catalog.import.confirmed',
                entity: $locked,
                oldValues: ['status' => 'ready'],
                newValues: ['status' => 'confirmed', 'rows' => $locked->valid_rows],
                actor: $actor,
                sourceChannel: 'dashboard',
            );
        }, attempts: 5);
    }

    /**
     * @param  array<string, string>  $payload
     * @return array<string, list<string>>
     */
    private function validateRow(array $payload): array
    {
        $errors = [];

        foreach (['sku', 'product_name', 'variant_name'] as $column) {
            if (($payload[$column] ?? '') === '') {
                $errors = $this->addError($errors, $column, 'required');
            }
        }

        foreach (['cost_price', 'base_price', 'min_price', 'markup_percent'] as $column) {
            if (isset($payload[$column]) && ! is_numeric($payload[$column])) {
                $errors = $this->addError($errors, $column, 'numeric');
            }
        }

        if (isset($payload['expires_at']) && strtotime($payload['expires_at']) === false) {
            $errors = $this->addError($errors, 'expires_at', 'date');
        }

        if (($payload['track_serials'] ?? 'false') === 'true' && ($payload['serial_number'] ?? '') === '') {
            return $this->addError($errors, 'serial_number', 'required_when_tracking_serials');
        }

        return $errors;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array<string, list<string>>
     */
    private function addError(array $errors, string $column, string $error): array
    {
        $errors[$column] = [...($errors[$column] ?? []), $error];

        return $errors;
    }

    /** @param list<string> $header @throws DomainException */
    private function assertTemplateColumns(array $header): void
    {
        if (array_diff(['sku', 'product_name', 'variant_name'], $header) !== []) {
            throw new DomainException(__('admin.inventory.import.errors.invalid_template'));
        }
    }

    private function applyRow(InventoryImportItem $item, User $actor): void
    {
        /** @var array<string, string> $payload */
        $payload = $item->payload;
        $brand = $this->resolveBrand($payload);
        $category = $this->resolveCategory($payload);
        $unit = $this->resolveUnit($payload);
        $product = Product::query()->firstOrNew(['name' => $payload['product_name']]);
        $product->forceFill([
            'name_ar' => $payload['product_name_ar'] ?? null,
            'status' => $payload['product_status'] ?? 'active',
            'brand_id' => $brand?->getKey(),
            'category_id' => $category?->getKey(),
            'created_by' => $product->exists ? $product->created_by : $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ])->save();

        $variant = ProductVariant::query()->updateOrCreate(
            ['sku' => $payload['sku']],
            [
                'product_id' => $product->getKey(),
                'name' => $payload['variant_name'],
                'name_ar' => $payload['variant_name_ar'] ?? null,
                'barcode' => $payload['barcode'] ?? null,
                'unit_id' => $unit?->getKey(),
                'track_serials' => $this->toBool($payload['track_serials'] ?? null),
                'track_expiry' => $this->toBool($payload['track_expiry'] ?? null),
                'cost_price' => $payload['cost_price'] ?? null,
                'base_price' => $payload['base_price'] ?? null,
                'min_price' => $payload['min_price'] ?? null,
                'markup_percent' => $payload['markup_percent'] ?? null,
                'status' => $payload['product_status'] ?? 'active',
            ],
        );

        $variant->forceFill(['updated_by' => $actor->getKey()])->save();

        if (isset($payload['cost_price'])) {
            $this->priceResolver->updateCost($variant, (float) $payload['cost_price'], $actor, isset($payload['min_price']) ? (float) $payload['min_price'] : null);
        }

        if (isset($payload['supplier_name']) || isset($payload['supplier_code'])) {
            $supplier = Supplier::query()->firstOrCreate(
                ['code' => $payload['supplier_code'] ?? Str::upper(Str::slug($payload['supplier_name']))],
                ['name' => $payload['supplier_name'] ?? $payload['supplier_code']],
            );

            SupplierProductReference::query()->updateOrCreate(
                ['supplier_id' => $supplier->getKey(), 'supplier_item_number' => $payload['supplier_item_number'] ?? $variant->sku],
                [
                    'product_variant_id' => $variant->getKey(),
                    'supplier_name' => $supplier->name,
                    'country_code' => $payload['country_code'] ?? null,
                    'manufacturer' => $payload['manufacturer'] ?? null,
                    'purchase_cost' => $payload['cost_price'] ?? null,
                    'currency_code' => $payload['currency_code'] ?? 'USD',
                ],
            );
        }
    }

    /** @param array<string, string> $payload */
    private function resolveBrand(array $payload): ?Brand
    {
        if (! isset($payload['brand_code']) && ! isset($payload['brand_name'])) {
            return null;
        }

        return Brand::query()->firstOrCreate(
            ['code' => $payload['brand_code'] ?? Str::upper(Str::slug($payload['brand_name']))],
            ['name' => $payload['brand_name'] ?? $payload['brand_code']],
        );
    }

    /** @param array<string, string> $payload */
    private function resolveCategory(array $payload): ?ProductCategory
    {
        if (! isset($payload['category_name'])) {
            return null;
        }

        $parent = isset($payload['parent_category_name'])
            ? ProductCategory::query()->firstOrCreate(['name' => $payload['parent_category_name']])
            : null;

        return ProductCategory::query()->firstOrCreate(
            ['name' => $payload['category_name'], 'parent_id' => $parent?->getKey()],
        );
    }

    /** @param array<string, string> $payload */
    private function resolveUnit(array $payload): ?Unit
    {
        if (! isset($payload['unit_symbol'])) {
            return null;
        }

        return Unit::query()->firstOrCreate(
            ['symbol' => $payload['unit_symbol']],
            ['name' => $payload['unit_name'] ?? $payload['unit_symbol'], 'allows_decimal' => $this->toBool($payload['allows_decimal'] ?? null)],
        );
    }

    private function cellValue(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value)) {
            return mb_trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return mb_trim((string) $value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return '';
    }

    /** @param list<string> $values */
    private function isBlankRow(array $values): bool
    {
        return collect($values)->every(fn (string $value): bool => $value === '');
    }

    private function toBool(?string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private function importRunId(InventoryImportRun $run): int
    {
        $key = $run->getKey();

        if (! is_int($key)) {
            throw new LogicException('Inventory import runs must use integer identifiers.');
        }

        return $key;
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private function rowPayload(array $header, array $values): array
    {
        $payload = [];

        foreach ($header as $index => $column) {
            $value = $values[$index] ?? '';

            if ($value !== '') {
                $payload[$column] = $value;
            }
        }

        return $payload;
    }
}
