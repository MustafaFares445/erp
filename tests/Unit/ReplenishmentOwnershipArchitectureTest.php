<?php

declare(strict_types=1);

it('keeps legacy reorder level out of canonical inventory surfaces', function (): void {
    foreach ([
        app_path('Models/InventoryStock.php'),
        app_path('Services/Inventory/InventoryAlertService.php'),
        database_path('factories/InventoryStockFactory.php'),
        database_path('migrations/2026_07_22_000006_create_inventory_stocks_table.php'),
    ] as $path) {
        $contents = file_get_contents($path);

        expect($contents)->not->toBeFalse();
        expect((string) $contents)->not->toContain('reorder_level');
    }
});

it('requires dedicated replenishment domain primitives', function (): void {
    foreach ([
        app_path('Models/WarehouseReplenishmentPolicy.php'),
        app_path('Models/ReplenishmentRequirement.php'),
        app_path('Models/ReplenishmentCoverage.php'),
        app_path('Services/Inventory/ReplenishmentProjectionService.php'),
        app_path('Services/Inventory/ReplenishmentRequirementService.php'),
        app_path('Services/Inventory/ReplenishmentCoverageService.php'),
        app_path('Services/Inventory/ReplenishmentTransferSuggestionService.php'),
    ] as $path) {
        expect($path)->toBeFile();
    }
});
