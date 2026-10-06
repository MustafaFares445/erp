<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Models\ProductVariant;
use App\Models\ReplenishmentRequirement;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentPolicy;
use App\Services\Inventory\ProductVariantUomService;
use App\Services\Purchasing\ReplenishmentPurchaseOrderDraftService;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();

    $this->buyer = User::factory()->create();
    $this->buyer->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($this->buyer);
});

it('requires a preferred replenishment supplier to have a current active product reference', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $eligible = Supplier::factory()->create();
    $ineligible = Supplier::factory()->create();

    SupplierProductReference::factory()->create([
        'supplier_id' => $eligible->getKey(),
        'product_variant_id' => $variant->getKey(),
        'currency_code' => 'AED',
        'purchase_cost' => '10.00',
        'availability_status' => 'active',
        'is_active' => true,
    ]);

    $policy = WarehouseReplenishmentPolicy::query()->create([
        'warehouse_id' => $warehouse->getKey(),
        'product_variant_id' => $variant->getKey(),
        'preferred_supplier_id' => $eligible->getKey(),
        'min_quantity' => '5.000000',
        'max_quantity' => '20.000000',
        'is_active' => true,
    ]);

    expect($policy->preferred_supplier_id)->toBe($eligible->getKey());

    expect(fn () => WarehouseReplenishmentPolicy::query()->create([
        'warehouse_id' => Warehouse::factory()->create()->getKey(),
        'product_variant_id' => $variant->getKey(),
        'preferred_supplier_id' => $ineligible->getKey(),
        'min_quantity' => '5.000000',
        'max_quantity' => '20.000000',
        'is_active' => true,
    ]))->toThrow(DomainException::class, 'Preferred replenishment supplier');
});

it('groups selected warehouse recommendations into canonical supplier drafts and rounds to purchase UOM MOQ', function (): void {
    $piece = Unit::factory()->whole()->create([
        'code' => 'REP-PIECE',
        'name' => 'Replenishment piece',
        'symbol' => 'RPI',
        'family' => 'count',
    ]);
    $box = Unit::factory()->whole()->create([
        'code' => 'REP-BOX',
        'name' => 'Replenishment box',
        'symbol' => 'RBX',
        'family' => 'count',
    ]);
    $variant = ProductVariant::factory()->create(['unit_id' => $piece->getKey()]);

    app(ProductVariantUomService::class)->sync($variant, [
        [
            'unit_id' => $piece->getKey(),
            'packaging_name' => null,
            'barcode' => null,
            'is_base' => true,
            'is_purchase' => true,
            'is_sale' => true,
            'is_display' => true,
            'factor_to_base' => '1',
            'rounding_increment' => '1',
            'permits_cross_family_conversion' => false,
            'is_active' => true,
        ],
        [
            'unit_id' => $box->getKey(),
            'packaging_name' => 'Box of 10',
            'barcode' => '6290000000200',
            'is_base' => false,
            'is_purchase' => true,
            'is_sale' => false,
            'is_display' => true,
            'factor_to_base' => '10',
            'rounding_increment' => '1',
            'permits_cross_family_conversion' => false,
            'is_active' => true,
        ],
    ]);

    $supplier = Supplier::factory()->create([
        'default_currency_code' => 'AED',
        'default_lead_time_days' => 5,
    ]);
    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_unit_id' => $box->getKey(),
        'minimum_order_quantity' => '3.000',
        'currency_code' => 'AED',
        'purchase_cost' => '2.00',
        'availability_status' => 'active',
        'is_active' => true,
    ]);

    $requirements = collect();

    foreach ([Warehouse::factory()->create(), Warehouse::factory()->create()] as $warehouse) {
        $policy = WarehouseReplenishmentPolicy::query()->create([
            'warehouse_id' => $warehouse->getKey(),
            'product_variant_id' => $variant->getKey(),
            'preferred_supplier_id' => $supplier->getKey(),
            'min_quantity' => '5.000000',
            'max_quantity' => '12.000000',
            'is_active' => true,
        ]);

        $requirement = ReplenishmentRequirement::query()
            ->where('warehouse_replenishment_policy_id', $policy->getKey())
            ->active()
            ->sole();
        $requirements->push($requirement);
    }

    $drafts = app(ReplenishmentPurchaseOrderDraftService::class)->createDrafts(
        $this->buyer,
        $requirements->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
    );

    expect($drafts)->toHaveCount(1);

    $draft = $drafts->firstOrFail()->refresh()->load('lines');

    expect($draft->supplier_id)->toBe($supplier->getKey())
        ->and($draft->currency_code)->toBe('AED')
        ->and($draft->expected_at?->toDateString())->toBe(today()->addDays(5)->toDateString())
        ->and($draft->notes)->toContain('Created from replenishment recommendations')
        ->and($draft->notes)->toContain('REQ-'.$requirements[0]->getKey())
        ->and($draft->notes)->toContain('REQ-'.$requirements[1]->getKey())
        ->and($draft->lines)->toHaveCount(1)
        ->and($draft->lines->first()->unit_id)->toBe($box->getKey())
        ->and($draft->lines->first()->quantity_ordered)->toBe('3.000000')
        ->and($draft->lines->first()->base_quantity)->toBe('30.000000')
        ->and($draft->lines->first()->unit_cost)->toBe('20.00');
});
