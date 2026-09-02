<?php

declare(strict_types=1);

use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryBalanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\SkippedWithMessageException;
use Symfony\Component\Process\Process;

it('allows exactly one concurrent source-stock withdrawal on MySQL', function (): void {
    $connection = 'warehouse_concurrency';
    $warehouseConcurrencyDatabase = config("database.connections.{$connection}.database");

    if (! is_string($warehouseConcurrencyDatabase) || $warehouseConcurrencyDatabase === '') {
        throw new SkippedWithMessageException('Configure WAREHOUSE_CONCURRENCY_DB_DATABASE with a dedicated migrated MySQL test database to run this process-concurrency harness.');
    }

    expect($warehouseConcurrencyDatabase)->toMatch('/(?:_test|_testing)(?:_\\d+)?$/');

    $databaseConnection = DB::connection($connection);

    expect($databaseConnection->getDriverName())->toBe('mysql');

    foreach (['products', 'product_units', 'product_variants', 'units', 'warehouses', 'inventory_stocks'] as $table) {
        expect(Schema::connection($connection)->hasTable($table))->toBeTrue("The dedicated warehouse concurrency database is missing its {$table} table.");
    }

    $originalConnection = DB::getDefaultConnection();
    DB::setDefaultConnection($connection);

    $variant = null;
    $product = null;
    $unit = null;
    $warehouse = null;
    $barrierPath = storage_path('framework/testing/warehouse-concurrency/'.Str::uuid()->toString());

    try {
        $variant = ProductVariant::factory()->create();
        $product = $variant->product;
        $unit = $variant->unit;
        $warehouse = Warehouse::factory()->create();

        expect($product)->toBeInstanceOf(Product::class)
            ->and($unit)->toBeInstanceOf(Unit::class);

        if (! $product instanceof Product || ! $unit instanceof Unit) {
            throw new LogicException('A product variant factory must create its product and unit relations.');
        }

        $variantId = $variant->id;
        $warehouseId = $warehouse->id;

        app(InventoryBalanceService::class)->receive($variant, $warehouseId, 1.0);

        File::ensureDirectoryExists($barrierPath);

        $worker = base_path('tests/Support/warehouse-balance-concurrency-worker.php');
        $first = new Process([
            PHP_BINARY,
            $worker,
            $connection,
            (string) $variantId,
            (string) $warehouseId,
            $barrierPath,
            'first',
        ], base_path(), ['APP_ENV' => 'testing']);
        $second = new Process([
            PHP_BINARY,
            $worker,
            $connection,
            (string) $variantId,
            (string) $warehouseId,
            $barrierPath,
            'second',
        ], base_path(), ['APP_ENV' => 'testing']);

        $first->start();
        $second->start();

        $deadline = microtime(true) + 30;

        while (! File::exists("{$barrierPath}/first.ready") || ! File::exists("{$barrierPath}/second.ready")) {
            foreach (['first' => $first, 'second' => $second] as $workerName => $process) {
                if ($process->isTerminated()) {
                    throw new RuntimeException(sprintf(
                        'Warehouse concurrency worker [%s] exited before the start barrier (exit=%s): %s',
                        $workerName,
                        (string) $process->getExitCode(),
                        mb_trim($process->getErrorOutput()),
                    ));
                }
            }

            if (microtime(true) > $deadline) {
                $firstError = mb_trim($first->getErrorOutput());
                $secondError = mb_trim($second->getErrorOutput());
                $first->stop();
                $second->stop();

                throw new RuntimeException(sprintf(
                    'Warehouse concurrency workers did not reach the start barrier within 30 seconds. first=%s second=%s',
                    $firstError === '' ? '<no stderr>' : $firstError,
                    $secondError === '' ? '<no stderr>' : $secondError,
                ));
            }

            usleep(10_000);
        }

        File::put("{$barrierPath}/go", 'go');
        $first->wait();
        $second->wait();

        $successfulWorkers = collect([$first, $second])
            ->filter(static fn (Process $process): bool => $process->isSuccessful())
            ->count();
        $remainingQuantity = InventoryStock::query()
            ->where('product_variant_id', $variantId)
            ->where('warehouse_id', $warehouseId)
            ->value('on_hand_quantity');

        if (! is_numeric($remainingQuantity)) {
            throw new LogicException('Concurrent balance workers must leave a numeric stock quantity.');
        }

        expect($successfulWorkers)->toBe(1)
            ->and((float) $remainingQuantity)
            ->toBe(0.0);
    } finally {
        if ($variant instanceof ProductVariant) {
            $databaseConnection->table('inventory_stocks')->where('product_variant_id', $variant->getKey())->delete();
            $databaseConnection->table('product_units')->where('product_id', $variant->product_id)->delete();
            $databaseConnection->table('product_variants')->where('id', $variant->getKey())->delete();
        }

        if ($product instanceof Product) {
            $databaseConnection->table('products')->where('id', $product->getKey())->delete();
        }

        if ($unit instanceof Unit) {
            $databaseConnection->table('units')->where('id', $unit->getKey())->delete();
        }

        if ($warehouse instanceof Warehouse) {
            $databaseConnection->table('warehouses')->where('id', $warehouse->getKey())->delete();
        }

        File::deleteDirectory($barrierPath);
        DB::setDefaultConnection($originalConnection);
        DB::purge($connection);
    }
});
