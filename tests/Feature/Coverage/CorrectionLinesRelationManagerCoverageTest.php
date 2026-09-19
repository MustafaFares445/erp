<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\InventoryCorrections\Pages\ViewInventoryCorrection;
use App\Filament\Resources\InventoryCorrections\RelationManagers\CorrectionLinesRelationManager;
use App\Models\InventoryCorrectionLine;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryCorrectionService;
use App\Services\Inventory\InventoryOperationService;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function correctionLinesCoverageFixture(): array
{
    (new InventoryPermissionSeeder)->run();

    $actor = User::factory()->create();
    $actor->givePermissionTo([
        InventoryPermission::CorrectionView->value,
        InventoryPermission::CorrectionCreate->value,
    ]);

    $warehouse = Warehouse::factory()->create();
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->create();

    $receipt = InventoryOperation::factory()->receipt()->create([
        'destination_warehouse_id' => $warehouse->getKey(),
        'supplier_id' => $supplier->getKey(),
    ]);
    $line = $receipt->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '3.000000',
        'unit_id' => $variant->unit_id,
        'lot_number' => 'CORR-RM-COVERAGE',
    ]);

    $operations = app(InventoryOperationService::class);
    $operations->markReady($receipt, $actor);
    $operations->complete($receipt->refresh(), $actor);

    $correction = app(InventoryCorrectionService::class)->createReceiptCorrection(
        $actor,
        $receipt->refresh(),
        'Relation manager coverage.',
    );

    return [$actor, $correction, $line->refresh(), $variant];
}

it('adds and removes a correction line through the relation manager actions', function (): void {
    [$actor, $correction, $operationLine] = correctionLinesCoverageFixture();

    Livewire::actingAs($actor)
        ->test(CorrectionLinesRelationManager::class, [
            'ownerRecord' => $correction,
            'pageClass' => ViewInventoryCorrection::class,
        ])
        ->callTableAction('addReceiptLine', data: [
            'original_inventory_operation_line_id' => $operationLine->getKey(),
            'transaction_quantity' => 1.25,
        ])
        ->assertHasNoActionErrors();

    $line = InventoryCorrectionLine::query()->sole();
    expect($line->transaction_quantity)->toBe('1.250000');

    Livewire::actingAs($actor)
        ->test(CorrectionLinesRelationManager::class, [
            'ownerRecord' => $correction->refresh(),
            'pageClass' => ViewInventoryCorrection::class,
        ])
        ->callTableAction('remove', $line)
        ->assertHasNoActionErrors();

    expect(InventoryCorrectionLine::query()->count())->toBe(0);
});

it('covers receipt options and defensive relation manager helpers', function (): void {
    [$actor, $correction, $operationLine, $variant] = correctionLinesCoverageFixture();
    $this->actingAs($actor);

    $manager = new CorrectionLinesRelationManager;
    $manager->ownerRecord = $correction;

    $options = new ReflectionMethod(CorrectionLinesRelationManager::class, 'receiptLineOptions')->invoke($manager);

    expect($options)->toHaveKey($operationLine->getKey())
        ->and($options[$operationLine->getKey()])->toContain($variant->sku);

    $record = new ReflectionMethod(CorrectionLinesRelationManager::class, 'correctionRecord')->invoke($manager);
    expect($record->is($correction))->toBeTrue();

    expect(fn (): mixed => new ReflectionMethod(CorrectionLinesRelationManager::class, 'integerKey')
        ->invoke(null, new InventoryOperationLine))
        ->toThrow(LogicException::class, 'integer identifiers');

    $invalid = new CorrectionLinesRelationManager;
    $invalid->ownerRecord = new ProductVariant;

    expect(fn (): mixed => new ReflectionMethod(CorrectionLinesRelationManager::class, 'correctionRecord')->invoke($invalid))
        ->toThrow(LogicException::class, 'Expected an InventoryCorrection');
});
