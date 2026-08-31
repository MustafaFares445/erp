<?php

declare(strict_types=1);

use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('keeps a transition-only product unit allow-list synchronized with variant UOMs', function (): void {
    $piece = uomUnit(['code' => 'PIECE', 'family' => 'count', 'allows_decimal' => false, 'precision' => 0]);
    $box = uomUnit(['code' => 'BOX', 'family' => 'count', 'allows_decimal' => false, 'precision' => 0]);
    $variant = ProductVariant::factory()->create();

    $configuredVariant = configureVariantUoms($variant, [
        uomDefinition($piece, isBase: true, factor: '1', increment: '1'),
        uomDefinition($box, factor: '100', increment: '1'),
    ]);

    $product = $configuredVariant->product;

    if (! $product instanceof Product) {
        throw new LogicException('A configured variant must have a product.');
    }

    expect($configuredVariant->unit_id)->toBe(uomUnitId($piece))
        ->and($configuredVariant->variantUnits()->where('is_base', true)->value('unit_id'))->toBe(uomUnitId($piece))
        ->and($product->units()->pluck('units.id')->all())
        ->toContain(uomUnitId($piece), uomUnitId($box));
});

it('locks the base unit and conversion metadata after a variant has stock history', function (): void {
    $piece = uomUnit(['code' => 'PIECE', 'family' => 'count', 'allows_decimal' => false, 'precision' => 0]);
    $box = uomUnit(['code' => 'BOX', 'family' => 'count', 'allows_decimal' => false, 'precision' => 0]);
    $variant = ProductVariant::factory()->create();

    $configuredVariant = configureVariantUoms($variant, [
        uomDefinition($piece, isBase: true, factor: '1', increment: '1'),
        uomDefinition($box, factor: '100', increment: '1'),
    ]);

    $warehouse = Warehouse::factory()->create();
    $warehouseId = $warehouse->getKey();

    if (! is_int($warehouseId)) {
        throw new LogicException('A warehouse fixture must have an integer ID.');
    }

    app(InventoryBalanceService::class)->receive($configuredVariant, $warehouseId, 1);

    expect(fn (): bool => $configuredVariant->update(['unit_id' => uomUnitId($box)]))->toThrow(ValidationException::class)
        ->and(fn (): bool => $configuredVariant->variantUnits()->where('unit_id', uomUnitId($box))->firstOrFail()->update(['factor_to_base' => '50']))->toThrow(ValidationException::class)
        ->and(fn (): bool => $piece->update(['family' => 'mass']))->toThrow(ValidationException::class);

    expect(InventoryStock::query()->where('product_variant_id', $configuredVariant->getKey())->value('on_hand_quantity'))->toBe('1.000');
});
