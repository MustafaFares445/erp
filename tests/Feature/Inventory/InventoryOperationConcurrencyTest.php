<?php

declare(strict_types=1);

use App\Enums\OperationStage;
use App\Enums\ReservationStatus;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\SkippedWithMessageException;
use Symfony\Component\Process\Process;

function canonicalWarehouseConcurrencyConnection(): string
{
    $connection = 'warehouse_concurrency';
    $database = config("database.connections.{$connection}.database");

    if (! is_string($database) || $database === '') {
        throw new SkippedWithMessageException(
            'Configure WAREHOUSE_CONCURRENCY_DB_DATABASE with a dedicated migrated MySQL test database.',
        );
    }

    expect($database)->toMatch('/(?:_test|_testing)(?:_\d+)?$/')
        ->and(DB::connection($connection)->getDriverName())->toBe('mysql');

    foreach ([
        'inventory_operations',
        'inventory_operation_lines',
        'inventory_reservations',
        'inventory_reservation_allocations',
        'inventory_movements',
        'inventory_stocks',
    ] as $table) {
        expect(Schema::connection($connection)->hasTable($table))
            ->toBeTrue("The dedicated warehouse concurrency database is missing its {$table} table.");
    }

    return $connection;
}

function resetCanonicalWarehouseConcurrencyDatabase(string $connection): void
{
    $database = DB::connection($connection);
    $schema = Schema::connection($connection);

    $database->statement('SET FOREIGN_KEY_CHECKS=0');

    try {
        foreach ($schema->getTableListing() as $table) {
            if ($table === 'migrations') {
                continue;
            }

            $database->table($table)->truncate();
        }
    } finally {
        $database->statement('SET FOREIGN_KEY_CHECKS=1');
    }
}

/** @return array{0: Process, 1: Process} */
function runCanonicalWarehouseRace(
    string $connection,
    int $operationId,
    string $action,
): array {
    $barrierPath = storage_path('framework/testing/warehouse-operation-concurrency/'.Str::uuid()->toString());
    File::ensureDirectoryExists($barrierPath);

    $worker = base_path('tests/Support/warehouse-operation-concurrency-worker.php');
    $processes = [
        new Process([
            PHP_BINARY,
            $worker,
            $connection,
            (string) $operationId,
            $barrierPath,
            'first',
            $action,
        ], base_path(), ['APP_ENV' => 'testing']),
        new Process([
            PHP_BINARY,
            $worker,
            $connection,
            (string) $operationId,
            $barrierPath,
            'second',
            $action,
        ], base_path(), ['APP_ENV' => 'testing']),
    ];

    foreach ($processes as $process) {
        $process->start();
    }

    $deadline = microtime(true) + 10;

    while (! File::exists("{$barrierPath}/first.ready") || ! File::exists("{$barrierPath}/second.ready")) {
        if (microtime(true) > $deadline) {
            foreach ($processes as $process) {
                $process->stop();
            }

            File::deleteDirectory($barrierPath);

            throw new RuntimeException('Canonical warehouse concurrency workers did not reach the start barrier.');
        }

        usleep(10_000);
    }

    File::put("{$barrierPath}/go", 'go');

    foreach ($processes as $process) {
        $process->wait();
    }

    File::deleteDirectory($barrierPath);

    return [$processes[0], $processes[1]];
}

/**
 * @return array{
 *     0: InventoryOperation,
 *     1: InventoryOperation,
 *     2: InventoryStock,
 *     3: InventoryLot
 * }
 */
function competingDeliveryOperations(): array
{
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    $stock = InventoryStock::factory()
        ->for($variant)
        ->for($warehouse)
        ->create([
            'on_hand_quantity' => '5.000000',
            'reserved_quantity' => '0.000000',
            'damaged_quantity' => '0.000000',
            'available_quantity' => '5.000000',
        ]);

    $lot = InventoryLot::factory()
        ->for($variant, 'productVariant')
        ->for($warehouse)
        ->create([
            'on_hand_quantity' => '5.000000',
            'reserved_quantity' => '0.000000',
            'expires_at' => null,
        ]);

    $operations = [];

    foreach ([1, 2] as $ignored) {
        $operation = InventoryOperation::factory()->delivery()->create([
            'source_warehouse_id' => $warehouse->getKey(),
        ]);
        $operation->lines()->create([
            'product_variant_id' => $variant->getKey(),
            'quantity' => '4.000000',
            'unit_id' => $variant->unit_id,
            'inventory_lot_id' => $lot->getKey(),
        ]);
        $operations[] = $operation;
    }

    return [$operations[0], $operations[1], $stock, $lot];
}

it('allows exactly one competing delivery reservation to become ready on MySQL', function (): void {
    $connection = canonicalWarehouseConcurrencyConnection();
    $originalConnection = DB::getDefaultConnection();
    DB::setDefaultConnection($connection);
    DB::purge($connection);

    try {
        resetCanonicalWarehouseConcurrencyDatabase($connection);
        [$firstOperation, $secondOperation, $stock] = competingDeliveryOperations();

        $firstBarrier = storage_path('framework/testing/warehouse-operation-concurrency/'.Str::uuid()->toString());
        File::ensureDirectoryExists($firstBarrier);

        $worker = base_path('tests/Support/warehouse-operation-concurrency-worker.php');
        $first = new Process([
            PHP_BINARY,
            $worker,
            $connection,
            (string) $firstOperation->getKey(),
            $firstBarrier,
            'first',
            'mark-ready',
        ], base_path(), ['APP_ENV' => 'testing']);
        $second = new Process([
            PHP_BINARY,
            $worker,
            $connection,
            (string) $secondOperation->getKey(),
            $firstBarrier,
            'second',
            'mark-ready',
        ], base_path(), ['APP_ENV' => 'testing']);

        $first->start();
        $second->start();

        $deadline = microtime(true) + 10;
        while (! File::exists("{$firstBarrier}/first.ready") || ! File::exists("{$firstBarrier}/second.ready")) {
            if (microtime(true) > $deadline) {
                $first->stop();
                $second->stop();
                throw new RuntimeException('Reservation concurrency workers did not reach the start barrier.');
            }
            usleep(10_000);
        }

        File::put("{$firstBarrier}/go", 'go');
        $first->wait();
        $second->wait();
        File::deleteDirectory($firstBarrier);

        $successfulWorkers = collect([$first, $second])
            ->filter(static fn (Process $process): bool => $process->isSuccessful())
            ->count();

        $stages = InventoryOperation::query()
            ->whereKey([$firstOperation->getKey(), $secondOperation->getKey()])
            ->pluck('stage')
            ->map(static fn (mixed $stage): string => $stage instanceof OperationStage ? $stage->value : (string) $stage)
            ->sort()
            ->values()
            ->all();

        expect($successfulWorkers)->toBe(1)
            ->and($stages)->toBe(['ready', 'waiting'])
            ->and($stock->refresh()->reserved_quantity)->toBe('4.000000')
            ->and($stock->available_quantity)->toBe('1.000000')
            ->and(InventoryReservation::query()->where('status', ReservationStatus::Active->value)->count())->toBe(1)
            ->and(InventoryMovement::query()->where('movement_type', 'reservation')->count())->toBe(1);
    } finally {
        resetCanonicalWarehouseConcurrencyDatabase($connection);
        DB::setDefaultConnection($originalConnection);
        DB::purge($connection);
    }
});

it('allows exactly one concurrent completion of the same ready delivery on MySQL', function (): void {
    $connection = canonicalWarehouseConcurrencyConnection();
    $originalConnection = DB::getDefaultConnection();
    DB::setDefaultConnection($connection);
    DB::purge($connection);

    try {
        resetCanonicalWarehouseConcurrencyDatabase($connection);
        [$operation, , $stock] = competingDeliveryOperations();

        // Remove the competing draft before preparing the single delivery race.
        InventoryOperation::query()
            ->whereKeyNot($operation->getKey())
            ->delete();

        app(InventoryOperationService::class)->markReady($operation);

        [$first, $second] = runCanonicalWarehouseRace(
            $connection,
            (int) $operation->getKey(),
            'complete',
        );

        $successfulWorkers = collect([$first, $second])
            ->filter(static fn (Process $process): bool => $process->isSuccessful())
            ->count();

        expect($successfulWorkers)->toBe(1)
            ->and($operation->refresh()->stage)->toBe(OperationStage::Done)
            ->and($stock->refresh()->on_hand_quantity)->toBe('1.000000')
            ->and($stock->reserved_quantity)->toBe('0.000000')
            ->and(InventoryReservation::query()->where('status', ReservationStatus::Consumed->value)->count())->toBe(1)
            ->and(InventoryMovement::query()->where('movement_type', 'sale')->count())->toBe(1);
    } finally {
        resetCanonicalWarehouseConcurrencyDatabase($connection);
        DB::setDefaultConnection($originalConnection);
        DB::purge($connection);
    }
});
