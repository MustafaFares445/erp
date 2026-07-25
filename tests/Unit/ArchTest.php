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
