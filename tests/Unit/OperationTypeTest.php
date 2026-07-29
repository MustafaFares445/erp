<?php

declare(strict_types=1);

use App\Enums\OperationType;

it('declares the warehouse requirements for each operation type', function (OperationType $type, bool $source, bool $destination): void {
    expect($type->requiresSourceWarehouse())->toBe($source)
        ->and($type->requiresDestinationWarehouse())->toBe($destination);
})->with([
    [OperationType::Receipt, false, true],
    [OperationType::Delivery, true, false],
    [OperationType::InternalTransfer, true, true],
]);
