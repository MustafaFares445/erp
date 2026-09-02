<?php

declare(strict_types=1);

use App\Services\Inventory\InventoryBalanceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($argc !== 6) {
    fwrite(STDERR, 'Invalid warehouse concurrency worker arguments.');

    exit(2);
}

[, $connection, $variantId, $warehouseId, $barrierPath, $worker] = $argv;

DB::setDefaultConnection($connection);
DB::purge($connection);

file_put_contents("{$barrierPath}/{$worker}.ready", 'ready');

$deadline = microtime(true) + 30;

while (! file_exists("{$barrierPath}/go")) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, 'Warehouse concurrency start barrier timed out.');

        exit(2);
    }

    usleep(10_000);
}

try {
    app(InventoryBalanceService::class)->transferOut((int) $variantId, (int) $warehouseId, 1.0);

    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable::class);

    exit(1);
}
