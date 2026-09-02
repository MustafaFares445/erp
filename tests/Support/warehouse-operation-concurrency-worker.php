<?php

declare(strict_types=1);

use App\Enums\OperationStage;
use App\Models\InventoryOperation;
use App\Services\Inventory\InventoryOperationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($argc !== 6) {
    fwrite(STDERR, 'Invalid warehouse operation concurrency worker arguments.');

    exit(2);
}

[, $connection, $operationId, $barrierPath, $worker, $action] = $argv;

DB::setDefaultConnection($connection);
DB::purge($connection);

file_put_contents("{$barrierPath}/{$worker}.ready", 'ready');

$deadline = microtime(true) + 30;

while (! file_exists("{$barrierPath}/go")) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, 'Warehouse operation concurrency start barrier timed out.');

        exit(2);
    }

    usleep(10_000);
}

try {
    $operation = InventoryOperation::query()->findOrFail((int) $operationId);
    $service = app(InventoryOperationService::class);

    $result = match ($action) {
        'mark-ready' => $service->markReady($operation),
        'complete' => $service->complete($operation),
        default => throw new LogicException('Unknown warehouse concurrency action.'),
    };

    $expectedStage = $action === 'mark-ready'
        ? OperationStage::Ready
        : OperationStage::Done;

    if ($result->stage !== $expectedStage) {
        fwrite(STDERR, 'Operation finished in '.$result->stage->value.' instead of '.$expectedStage->value.'.');

        exit(1);
    }

    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable::class.': '.$throwable->getMessage());

    exit(1);
}
