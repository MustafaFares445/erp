<?php

declare(strict_types=1);

use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use Illuminate\Support\Facades\Route;

it('retires the legacy transfer resource route in favor of the canonical internal-transfer screen', function (): void {
    expect(Route::has('filament.admin.resources.transfers.index'))->toBeFalse()
        ->and(InventoryOperationResource::getUrl('transfers'))->toContain('/internal-transfers');
});
