<?php

declare(strict_types=1);

use App\Data\Inventory\DamageDraftData;
use App\Enums\ConditionChangeReason;
use App\Enums\InventoryPermission;
use App\Filament\Resources\InventoryConditionChanges\InventoryConditionChangeResource;
use App\Models\InventoryConditionChange;
use App\Models\InventoryLot;
use App\Models\InventoryStock;
use App\Models\User;
use App\Services\Inventory\InventoryConditionChangeService;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

it('scopes recovery damage-document choices to the originating stock row context', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo([
        InventoryPermission::ConditionChangeView->value,
        InventoryPermission::ConditionChangeCreate->value,
        InventoryPermission::ConditionChangePost->value,
        InventoryPermission::StockView->value,
    ]);

    $postDamage = function (InventoryStock $stock, string $reason) use ($actor): InventoryConditionChange {
        $lot = InventoryLot::factory()
            ->for($stock->productVariant)
            ->for($stock->warehouse)
            ->create([
                'on_hand_quantity' => '10.000000',
                'reserved_quantity' => '0.000000',
                'expires_at' => null,
            ]);

        $service = app(InventoryConditionChangeService::class);
        $damage = $service->draftDamage(new DamageDraftData(
            productVariantId: (int) $stock->product_variant_id,
            warehouseId: (int) $stock->warehouse_id,
            inventoryLotId: $lot->getKey(),
            serializedInventoryUnitId: null,
            baseQuantity: '1.000000',
            reasonCategory: ConditionChangeReason::DamagedInTransit,
            reason: $reason,
        ), $actor);

        return $service->post($damage, $actor);
    };

    $stock = InventoryStock::factory()->create([
        'on_hand_quantity' => 10,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 10,
    ]);
    $otherStock = InventoryStock::factory()->create([
        'on_hand_quantity' => 10,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 10,
    ]);

    $damage = $postDamage($stock, 'Damage for selected stock row');
    $otherDamage = $postDamage($otherStock, 'Damage for another stock row');

    $scopedOptions = InventoryConditionChangeResource::recoveryDamageDocumentOptions(
        productVariantId: (int) $stock->product_variant_id,
        warehouseId: (int) $stock->warehouse_id,
    );

    expect($scopedOptions)
        ->toHaveKey($damage->getKey())
        ->not->toHaveKey($otherDamage->getKey())
        ->and($scopedOptions[$damage->getKey()])->toBe($damage->document_number);

    $unscopedOptions = InventoryConditionChangeResource::recoveryDamageDocumentOptions();

    expect($unscopedOptions)
        ->toHaveKey($damage->getKey())
        ->toHaveKey($otherDamage->getKey());
});
