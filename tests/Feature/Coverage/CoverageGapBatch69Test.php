<?php

declare(strict_types=1);

use App\Filament\Resources\Quotations\Schemas\QuotationLinesRepeater;
use App\Models\ProductVariant;
use App\Services\Inventory\ProductMediaSynchronizer;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

function batch69Method(string $method): ReflectionMethod
{
    return new ReflectionMethod(QuotationLinesRepeater::class, $method);
}

/** @return array<string, object> */
function batch69Components(): array
{
    $repeater = QuotationLinesRepeater::make();
    $components = [];

    $collect = static function (iterable $children) use (&$collect, &$components): void {
        foreach ($children as $component) {
            if (method_exists($component, 'getName')) {
                $name = $component->getName();
                if (is_string($name) && $name !== '') {
                    $components[$name] = $component;
                }
            }

            try {
                $property = new ReflectionProperty($component, 'childComponents');
                $sets = $property->getValue($component);
                foreach (is_array($sets) ? $sets : [] as $childrenSet) {
                    if (is_iterable($childrenSet)) {
                        $collect($childrenSet);
                    }
                }
            } catch (ReflectionException) {
                // Leaf component.
            }
        }
    };

    $property = new ReflectionProperty($repeater, 'childComponents');
    $sets = $property->getValue($repeater);
    foreach (is_array($sets) ? $sets : [] as $childrenSet) {
        if (is_iterable($childrenSet)) {
            $collect($childrenSet);
        }
    }

    return $components;
}

it('re-resolves tier price when a quotation variant changes', function (): void {
    $variant = ProductVariant::factory()->create(['base_price' => 125.50]);
    $components = batch69Components();

    /** @var Select $variantSelect */
    $variantSelect = $components['product_variant_id'];
    $callbacks = new ReflectionProperty($variantSelect, 'afterStateUpdated')->getValue($variantSelect);

    $set = Mockery::mock(Set::class);
    $set->shouldReceive('__invoke')->once()->with('unit_id', $variant->unit_id);
    $set->shouldReceive('__invoke')->once()->with('unit_price', Mockery::type('float'));

    $get = Mockery::mock(Get::class);
    $get->shouldReceive('__invoke')->with('use_tier_price')->andReturn(true);
    $get->shouldReceive('__invoke')->with('../../customer_id')->andReturn(null);

    $callbacks[0]($set, $get, $variant->getKey());
});

it('renders a real quotation variant image when media exists', function (): void {
    $variant = ProductVariant::factory()->create();
    $path = UploadedFile::fake()->image('quotation-line.png')->store('product-images', 'public');
    app(ProductMediaSynchronizer::class)->sync($variant, [$path]);

    $preview = batch69Method('productImagePreview')->invoke(null, $variant->refresh()->getKey());

    expect((string) $preview)->toContain('<img', 'conversions/');
});

it('returns no floor warning when the entered base-equivalent price meets the floor', function (): void {
    $variant = ProductVariant::factory()->create([
        'min_price' => 50,
        'base_price' => 75,
    ]);

    $get = Mockery::mock(Get::class);
    $get->shouldReceive('__invoke')->andReturnUsing(
        static fn (string $path): mixed => match ($path) {
            'product_variant_id' => $variant->getKey(),
            'unit_price' => 75,
            'unit_id' => null,
            'price_floor_override_id' => null,
            default => null,
        },
    );

    expect(batch69Method('belowFloorHelperText')->invoke(null, $get))->toBeNull();
});

it('returns false for a floor override reason when a numeric variant no longer exists', function (): void {
    $get = Mockery::mock(Get::class);
    $get->shouldReceive('__invoke')->andReturnUsing(
        static fn (string $path): mixed => match ($path) {
            'price_floor_override_id' => null,
            'product_variant_id' => 999999999,
            default => null,
        },
    );

    expect(batch69Method('needsFloorOverrideReason')->invoke(null, $get))->toBeFalse();
});

it('uses a factor of one when no quotation unit conversion is selected', function (): void {
    $variant = ProductVariant::factory()->create();

    $get = Mockery::mock(Get::class);
    $get->shouldReceive('__invoke')->andReturnUsing(
        static fn (string $path): mixed => match ($path) {
            'unit_price' => 20,
            'unit_id' => null,
            default => null,
        },
    );

    expect(batch69Method('baseEquivalentPrice')->invoke(null, $get, $variant))->toBe(20.0);
});

it('resolves a variant price without a customer and converts digit strings to integers', function (): void {
    $variant = ProductVariant::factory()->create(['base_price' => 80]);

    $get = Mockery::mock(Get::class);
    $get->shouldReceive('__invoke')->with('../../customer_id')->andReturn(null);

    $resolved = batch69Method('resolvePrice')->invoke(null, $variant->getKey(), $get);

    expect($resolved)->not->toBeNull()
        ->and(batch69Method('toInteger')->invoke(null, '42'))->toBe(42);
});
