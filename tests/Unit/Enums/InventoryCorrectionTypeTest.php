<?php

declare(strict_types=1);

use App\Enums\InventoryCorrectionType;
use App\Enums\OperationType;

it('maps every correction type to its one allowed origin operation type', function (): void {
    expect(InventoryCorrectionType::Receipt->originOperationType())->toBe(OperationType::Receipt)
        ->and(InventoryCorrectionType::Delivery->originOperationType())->toBe(OperationType::Delivery)
        ->and(InventoryCorrectionType::Transfer->originOperationType())->toBe(OperationType::InternalTransfer);
});

it('exposes exactly the three documented correction types', function (): void {
    expect(InventoryCorrectionType::cases())->toHaveCount(3)
        ->and(array_map(fn (InventoryCorrectionType $type): string => $type->value, InventoryCorrectionType::cases()))
        ->toBe(['receipt', 'delivery', 'transfer']);
});
