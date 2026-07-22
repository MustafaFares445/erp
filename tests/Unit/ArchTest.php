<?php

declare(strict_types=1);

arch()->preset()->php();
arch()->preset()->strict();
arch()->preset()->laravel();
arch()->preset()->security();

it('never writes stock balances or movement records directly from a Filament class', function (): void {
    expect('App\Filament')->not->toUse([
        'App\Models\InventoryStock',
        'App\Models\InventoryMovement',
    ]);
});
