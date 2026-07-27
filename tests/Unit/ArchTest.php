<?php

declare(strict_types=1);
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\PriceFloorOverride;

arch()->preset()->php();
arch()->preset()->strict()->ignoring([
    'App\Filament',
    'App\Policies',
    'App\Models\Concerns',
    PriceFloorOverride::class,
    'Database',
]);
arch()->preset()->laravel();
arch()->preset()->security();

// Intent: no App\Filament code path may write stock balances or movement
// records. Stock levels, movements, returns, reports, and widgets may read
// these models through tested read-only surfaces. Every other Filament
// namespace remains banned, so write surfaces must use domain services.
// See specs/002-warehouses-stock-visibility/research.md R1.
//
// App\Filament\Resources\InventoryOperations and App\Filament\Resources\Packages
// (specs/014-inventory-erp-rework) are deliberately absent from the ignoring()
// list below and must stay that way: both write stock exclusively through
// InventoryOperationService, never directly (contracts/inventory-operations.md
// P-2). Because this assertion targets the whole App\Filament namespace, it
// already covers those two namespaces the moment their classes exist — no
// second assertion is needed, only the discipline not to except them here.
it('never writes stock balances or movement records directly from a Filament class', function (): void {
    expect('App\Filament')
        ->not->toUse([
            InventoryStock::class,
            InventoryMovement::class,
        ])
        ->ignoring([
            'App\Filament\Resources\StockLevels',
            'App\Filament\Resources\StockMovements',
            'App\Filament\Resources\Returns',
            'App\Filament\Resources\InventoryReports',
            'App\Filament\Resources\InventoryAlerts',
            'App\Filament\Widgets',
        ]);
});
