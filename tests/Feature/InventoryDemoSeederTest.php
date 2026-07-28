<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\InventoryOperation;
use App\Models\InventoryReceipt;
use App\Models\Package;
use App\Models\PackageType;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttributeValue;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use Database\Seeders\DentalCatalogSeeder;
use Database\Seeders\InventoryDemoSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds an idempotent dental catalogue without demo inventory data', function (): void {
    $seeder = new DentalCatalogSeeder;

    $seeder->run();
    $seeder->run();

    expect(Brand::query()->whereIn('code', ['FORMLABS', 'DENTSPLY-SIRONA', 'IVOCLAR'])->count())->toBe(3)
        ->and(Product::query()->count())->toBe(7)
        ->and(ProductVariant::query()->count())->toBe(15)
        ->and(ProductAttribute::query()->count())->toBe(9)
        ->and(ProductAttributeValue::query()->count())->toBe(34)
        ->and(ProductVariantAttributeValue::query()->count())->toBeGreaterThan(50)
        ->and(ProductVariantAttributeValue::query()->whereHas('variant', fn (Builder $query): Builder => $query->where('sku', 'FORMLABS-FORM-4B'))->count())->toBe(6)
        ->and(ProductVariant::query()->where('sku', 'like', 'DEMO-%')->exists())->toBeFalse();
});

it('seeds connected purchasing and inventory workflow scenarios idempotently', function (): void {
    $seeder = new InventoryDemoSeeder;

    $seeder->run();
    $seeder->run();

    expect(Supplier::query()->whereIn('code', ['FORMLABS-US', 'DENTSPLY-MENA', 'IVOCLAR-LEVANT'])->count())->toBe(3)
        ->and(PackageType::query()->count())->toBe(5)
        ->and(Package::query()->count())->toBe(3)
        ->and(Package::query()->whereHas('operationLines')->count())->toBe(2)
        ->and(Product::query()->whereHas('media')->count())->toBe(7)
        ->and(Product::query()->where('name', 'Precision Model Resin')->firstOrFail()->getMedia('images'))->toHaveCount(2)
        ->and(SupplierProductReference::query()->count())->toBe(7)
        ->and(InventoryReceipt::query()->whereNotNull('supplier_id')->count())->toBe(2)
        ->and(InventoryOperation::query()->where('notes', 'Demo workflow: delivered Formlabs replenishment.')->where('stage', 'done')->count())->toBe(1)
        ->and(InventoryOperation::query()->where('notes', 'Demo workflow: reserved resin for Smile Dental Clinic.')->where('stage', 'ready')->count())->toBe(1)
        ->and(InventoryOperation::query()->where('notes', 'Demo workflow: cold-chain stock transfer awaiting receipt.')->where('stage', 'in_transit')->count())->toBe(1)
        ->and(InventoryOperation::query()->where('notes', 'Demo workflow: draft Dentsply purchase order pending approval.')->where('stage', 'draft')->count())->toBe(1)
        ->and(InventoryOperation::query()->where('notes', 'Demo workflow: waiting for unavailable Primeprint PPU stock.')->where('stage', 'waiting')->count())->toBe(1);
});
