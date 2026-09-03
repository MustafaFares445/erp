<?php

declare(strict_types=1);

use App\Enums\QuarantineDisposition;
use App\Enums\StockCondition;

it('maps every quarantine disposition to its documented target condition', function (): void {
    expect(QuarantineDisposition::ReleaseToSaleable->conditionTo())->toBe(StockCondition::Saleable)
        ->and(QuarantineDisposition::DowngradeToDamaged->conditionTo())->toBe(StockCondition::Damaged)
        ->and(QuarantineDisposition::Dispose->conditionTo())->toBe(StockCondition::Disposed)
        ->and(QuarantineDisposition::ReturnToSupplier->conditionTo())->toBe(StockCondition::Saleable);
});

it('delegates only return-to-supplier to the supplier return workflow', function (): void {
    expect(QuarantineDisposition::ReturnToSupplier->requiresSupplierReturn())->toBeTrue();

    foreach ([
        QuarantineDisposition::ReleaseToSaleable,
        QuarantineDisposition::DowngradeToDamaged,
        QuarantineDisposition::Dispose,
    ] as $disposition) {
        expect($disposition->requiresSupplierReturn())->toBeFalse();
    }
});
