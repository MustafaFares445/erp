<?php

declare(strict_types=1);

use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use Illuminate\Support\Facades\Route;

it('retires the legacy transfer resource route and class in favor of the canonical internal-transfer screen', function (): void {
    expect(Route::has('filament.admin.resources.transfers.index'))->toBeFalse()
        ->and(class_exists('App\\Filament\\Resources\\Transfers\\TransferResource'))->toBeFalse()
        ->and(class_exists('App\\Services\\Inventory\\StockTransferService'))->toBeFalse()
        ->and(InventoryOperationResource::getUrl('transfers'))->toContain('/internal-transfers');
});
