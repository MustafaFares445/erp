<?php

declare(strict_types=1);

use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\PackageTypes\PackageTypeResource;
use App\Models\Package;
use App\Models\PackageType;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('package type and package resources expose the inventory management routes', function (): void {
    expect(PackageTypeResource::getPages())->toHaveKeys(['index', 'create', 'view', 'edit'])
        ->and(PackageResource::getPages())->toHaveKeys(['index', 'create', 'view', 'edit']);
});

test('a package belongs to its selected type and warehouse without carrying a stock quantity', function (): void {
    $type = PackageType::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $package = Package::factory()->for($type, 'packageType')->for($warehouse)->create();

    expect($package->package_type_id)->toBe($type->getKey())
        ->and($package->warehouse_id)->toBe($warehouse->getKey())
        ->and($package->getAttributes())->not->toHaveKey('quantity');
});
