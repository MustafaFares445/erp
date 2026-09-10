<?php

declare(strict_types=1);

it('keeps the inventory service boundary at the audited 32 non-pricing classes', function (): void {
    $expected = [
        'CatalogCompatibilityContractService.php',
        'CatalogCompatibilityEnforcementService.php',
        'CatalogCompatibilityService.php',
        'CatalogOptionSetService.php',
        'CatalogOptionValueService.php',
        'CountryOfOriginService.php',
        'DeliveryLineService.php',
        'DisposalService.php',
        'InventoryAdjustmentService.php',
        'InventoryAlertService.php',
        'InventoryBalanceService.php',
        'InventoryConditionService.php',
        'InventoryCorrectionService.php',
        'InventoryCountService.php',
        'InventoryDamageService.php',
        'InventoryExportService.php',
        'InventoryIdentityService.php',
        'InventoryLotReconciliationService.php',
        'InventoryLotService.php',
        'InventoryOperationService.php',
        'InventoryPostingService.php',
        'InventoryReportFormatter.php',
        'InventoryReportService.php',
        'InventoryReservationService.php',
        'InventoryReturnService.php',
        'ProductMediaService.php',
        'ProductTypeConfigService.php',
        'ProductVariantUomService.php',
        'QuantityNormalizer.php',
        'SerializedInventoryService.php',
        'StockReconciliationService.php',
        'StockService.php',
    ];

    $actual = array_map('basename', glob(app_path('Services/Inventory/*.php')) ?: []);
    sort($actual);

    expect($actual)
        ->toHaveCount(32)
        ->toBe($expected);

    $pricingOwners = [
        'PriceResolver',
        'PricingTierDiscountCalculator',
        'PricingTierService',
        'ProductPricingService',
        'LinePricingService',
    ];

    foreach ($actual as $file) {
        $source = file_get_contents(app_path("Services/Inventory/{$file}"));

        expect($source)->not->toBeFalse();

        foreach ($pricingOwners as $pricingOwner) {
            expect($source)->not->toContain($pricingOwner);
        }
    }
});
