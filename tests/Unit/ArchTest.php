<?php

declare(strict_types=1);
use App\Models\InventoryMovement;
use App\Models\InventoryStock;

arch()->preset()->php();
arch()->preset()->strict();
arch()->preset()->laravel();
arch()->preset()->security();

// Intent: no App\Filament code path may WRITE stock balances or the
// movement ledger. StockLevels/StockMovements are excepted because they
// legitimately READ these models (they are strictly read-only resources —
// see StockLevelResourceTest / StockMovementResourceTest, which prove no
// create/edit/delete action exists there, and their policies deny every
// write ability). Every other Filament namespace remains fully banned, so
// a future write surface (e.g. Adjustments, Transfers) must delegate to a
// domain service rather than touching these models directly.
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
        ]);
});
