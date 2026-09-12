<?php

declare(strict_types=1);

it('keeps the inventory service boundary free of Sales pricing computation', function (): void {
    $expected = [
        'CatalogImportApplicationService.php',
        'CatalogImportCatalogService.php',
        'CatalogImportReportService.php',
        'CatalogImportService.php',
        'CatalogImportValidator.php',
        'CountryNameResolver.php',
        'DeliveryDocumentSynchronizer.php',
        'DisposalEvidenceSynchronizer.php',
        'InventoryAdjustmentService.php',
        'InventoryAlertService.php',
        'InventoryBalanceService.php',
        'InventoryConditionChangeService.php',
        'InventoryCorrectionService.php',
        'InventoryCountService.php',
        'InventoryDamageService.php',
        'InventoryExportService.php',
        'InventoryIdentityGuard.php',
        'InventoryLotReconciliationService.php',
        'InventoryLotService.php',
        'InventoryOperationService.php',
        'InventoryPostingService.php',
        'InventoryReportFormatter.php',
        'InventoryReportService.php',
        'InventoryReservationService.php',
        'InventoryReturnService.php',
        'InventoryValuationService.php',
        'ProductMediaSynchronizer.php',
        'ProductTypeGuard.php',
        'ProductVariantUomService.php',
        'QuantityNormalizer.php',
        'ReconciliationReportService.php',
        'ReplenishmentCoverageService.php',
        'ReplenishmentProjectionService.php',
        'ReplenishmentRequirementService.php',
        'ReplenishmentTransferSuggestionService.php',
        'SerializedInventoryTimelineService.php',
        'StockAvailabilityExplainer.php',
    ];

    $actual = array_map(basename(...), glob(app_path('Services/Inventory/*.php')) ?: []);
    sort($actual);

    expect($actual)
        ->toHaveCount(37)
        ->toBe($expected);

    // Inventory must never compute a price itself — that stays Sales' job.
    // These classes are fully forbidden everywhere in app/Services/Inventory.
    $pricingComputationOwners = [
        'PriceResolver',
        'PricingTierDiscountCalculator',
        'PricingTierService',
        'LinePricingService',
    ];

    // ProductPricingService is different: it exposes two write-entry-points —
    // updateCostFromInventory() and updateFromInventoryImport() — built for
    // Inventory to *report* a cost/price change through Sales' own validated,
    // locked write path, rather than writing pricing columns directly. Only
    // the two files that use them may reference the class, and only through
    // those two methods; every other ProductPricingService method (pricing
    // requests, approvals, floor overrides) stays off-limits.
    $costReportingCallers = [
        'CatalogImportCatalogService.php' => 'updateFromInventoryImport',
        'InventoryOperationService.php' => 'updateCostFromInventory',
    ];
    $forbiddenPricingServiceMethods = [
        'updateVariantPricing',
        'approvePriceChangeRequest',
        'rejectPriceChangeRequest',
        'updatePriceChangeRequest',
        'approveFloorOverride',
    ];

    foreach ($actual as $file) {
        $source = file_get_contents(app_path("Services/Inventory/{$file}"));

        expect($source)->not->toBeFalse();

        foreach ($pricingComputationOwners as $owner) {
            expect($source)->not->toContain($owner);
        }

        if (! array_key_exists($file, $costReportingCallers)) {
            expect($source)->not->toContain('ProductPricingService');

            continue;
        }

        expect($source)->toContain('->'.$costReportingCallers[$file].'(');

        foreach ($forbiddenPricingServiceMethods as $method) {
            expect($source)->not->toContain('->'.$method.'(');
        }
    }
});
