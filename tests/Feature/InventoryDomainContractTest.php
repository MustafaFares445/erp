<?php

declare(strict_types=1);

use App\Data\Inventory\InventoryImportRowResult;
use App\Enums\InventoryExportType;
use App\Enums\InventoryImportItemStatus;
use App\Enums\InventoryImportRunStatus;
use App\Enums\InventoryReportType;
use App\Models\Brand;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttributeValue;
use App\Models\StockReservation;
use App\Models\Supplier;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes complete import and export state semantics', function (): void {
    expect(InventoryImportItemStatus::Valid->isTerminal())->toBeFalse()
        ->and(InventoryImportItemStatus::Applying->isTerminal())->toBeFalse()
        ->and(InventoryImportItemStatus::Invalid->isTerminal())->toBeTrue()
        ->and(InventoryImportItemStatus::Applied->isTerminal())->toBeTrue()
        ->and(InventoryImportItemStatus::Rejected->isTerminal())->toBeTrue()
        ->and(InventoryImportRunStatus::Queued->canApply())->toBeFalse()
        ->and(InventoryImportRunStatus::Ready->canApply())->toBeTrue()
        ->and(InventoryImportRunStatus::ReadyWithErrors->canApply())->toBeTrue()
        ->and(InventoryImportRunStatus::Applying->isTerminal())->toBeFalse()
        ->and(InventoryImportRunStatus::Invalid->isTerminal())->toBeTrue()
        ->and(InventoryImportRunStatus::Confirmed->isTerminal())->toBeTrue()
        ->and(InventoryImportRunStatus::ConfirmedWithErrors->isTerminal())->toBeTrue()
        ->and(InventoryImportRunStatus::Failed->isTerminal())->toBeTrue();

    foreach (InventoryExportType::cases() as $type) {
        expect($type->reports())->toContain($type->primaryReport())
            ->and($type->requiresPricing())->toBe($type->primaryReport()->requiresPricing());
    }

    expect(InventoryExportType::PricingTiers->reports())->toBe([
        InventoryReportType::PricingTiers,
        InventoryReportType::CustomerAssignments,
        InventoryReportType::FloorOverrides,
    ])->and(InventoryExportType::ImportResults->reports())->toBe([
        InventoryReportType::ImportRuns,
        InventoryReportType::ImportResults,
    ])->and(InventoryExportType::options())->toHaveCount(count(InventoryExportType::cases()));
});

it('returns every affected identifier from an import row result', function (): void {
    $variant = ProductVariant::factory()->create();
    $result = InventoryImportRowResult::forVariant($variant, 'created');
    $result->inventoryReceiptId = 10;
    $result->inventoryReceiptItemId = 11;
    $result->serializedInventoryUnitId = 12;
    $result->inventoryLotId = 13;

    expect($result->values())->toBe([
        'product_id' => $variant->product_id,
        'product_variant_id' => $variant->getKey(),
        'inventory_receipt_id' => 10,
        'inventory_receipt_item_id' => 11,
        'serialized_inventory_unit_id' => 12,
        'inventory_lot_id' => 13,
        'catalog_operation' => 'created',
    ])->and(fn (): InventoryImportRowResult => InventoryImportRowResult::forVariant(new ProductVariant, 'created'))
        ->toThrow(LogicException::class);
});

it('defines the catalog and inventory relationships used by report queries', function (): void {
    expect((new Brand)->products())->toBeInstanceOf(HasMany::class)
        ->and((new ProductAttribute)->values())->toBeInstanceOf(HasMany::class)
        ->and((new ProductAttributeValue)->attribute())->toBeInstanceOf(BelongsTo::class)
        ->and((new ProductAttributeValue)->variantAssignments())->toBeInstanceOf(HasMany::class)
        ->and((new ProductCategory)->parent())->toBeInstanceOf(BelongsTo::class)
        ->and((new ProductCategory)->children())->toBeInstanceOf(HasMany::class)
        ->and((new ProductCategory)->products())->toBeInstanceOf(HasMany::class)
        ->and((new ProductVariant)->serializedUnits())->toBeInstanceOf(HasMany::class)
        ->and((new ProductVariant)->lots())->toBeInstanceOf(HasMany::class)
        ->and((new ProductVariant)->priceHistories())->toBeInstanceOf(HasMany::class)
        ->and((new ProductVariantAttributeValue)->variant())->toBeInstanceOf(BelongsTo::class)
        ->and((new ProductVariantAttributeValue)->attributeValue())->toBeInstanceOf(BelongsTo::class)
        ->and((new Supplier)->productReferences())->toBeInstanceOf(HasMany::class)
        ->and((new Supplier)->receipts())->toBeInstanceOf(HasMany::class)
        ->and((new Unit)->variants())->toBeInstanceOf(HasMany::class)
        ->and((new InventoryMovement)->receiptItem())->toBeInstanceOf(BelongsTo::class)
        ->and((new InventoryMovement)->serializedUnit())->toBeInstanceOf(BelongsTo::class)
        ->and((new InventoryMovement)->lot())->toBeInstanceOf(BelongsTo::class)
        ->and((new InventoryReceipt)->supplier())->toBeInstanceOf(BelongsTo::class)
        ->and((new StockReservation)->productVariant())->toBeInstanceOf(BelongsTo::class)
        ->and((new StockReservation)->warehouse())->toBeInstanceOf(BelongsTo::class);
});
