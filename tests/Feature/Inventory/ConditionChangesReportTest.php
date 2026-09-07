<?php

declare(strict_types=1);

use App\Data\Inventory\DamageDraftData;
use App\Data\Inventory\DisposalDraftData;
use App\Enums\ConditionChangeReason;
use App\Enums\InventoryConditionChangeType;
use App\Enums\InventoryExportType;
use App\Enums\InventoryPermission;
use App\Enums\InventoryReportType;
use App\Models\InventoryLot;
use App\Models\InventoryStock;
use App\Models\User;
use App\Services\Inventory\InventoryConditionChangeService;
use App\Services\Inventory\InventoryReportFormatter;
use App\Services\Inventory\InventoryReportService;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

function reportActor(): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo([
        InventoryPermission::ReportView->value,
        InventoryPermission::ConditionChangeView->value,
        InventoryPermission::ConditionChangeCreate->value,
        InventoryPermission::ConditionChangePost->value,
    ]);

    return $actor;
}

/**
 * WP-3.3 (GAP-UI-06) — the shrinkage report: damage, recovery, and disposal
 * by period, variant, warehouse, and cause.
 */
it('reports only posted damage-family condition changes, scoped by type and warehouse', function (): void {
    $actor = reportActor();
    $service = app(InventoryConditionChangeService::class);

    $stock = InventoryStock::factory()->create([
        'on_hand_quantity' => 10, 'reserved_quantity' => 0, 'damaged_quantity' => 0, 'available_quantity' => 10,
    ]);
    $lot = InventoryLot::factory()->for($stock->productVariant)->for($stock->warehouse)->create([
        'on_hand_quantity' => '10.000000', 'reserved_quantity' => '0.000000',
    ]);

    $damage = $service->draftDamage(new DamageDraftData(
        productVariantId: (int) $stock->product_variant_id,
        warehouseId: (int) $stock->warehouse_id,
        inventoryLotId: $lot->getKey(),
        serializedInventoryUnitId: null,
        baseQuantity: '2.000000',
        reasonCategory: ConditionChangeReason::DamagedInTransit,
        reason: 'Transit damage',
    ), $actor);
    $postedDamage = $service->post($damage, $actor);

    // A draft disposal must not appear in the report — only posted documents do.
    $service->draftDisposal(new DisposalDraftData(
        productVariantId: (int) $stock->product_variant_id,
        warehouseId: (int) $stock->warehouse_id,
        inventoryLotId: $lot->getKey(),
        serializedInventoryUnitId: null,
        baseQuantity: '1.000000',
        reasonCategory: ConditionChangeReason::QualityInspectionFailed,
        reason: 'Beyond repair',
        authorisedBy: (int) User::factory()->create()->getKey(),
    ), $actor);

    $reportService = app(InventoryReportService::class);
    $rows = $reportService->query(InventoryReportType::ConditionChanges)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()?->getKey())->toBe($postedDamage->getKey());

    $typeFiltered = $reportService->query(InventoryReportType::ConditionChanges, [
        'type' => InventoryConditionChangeType::Disposal->value,
    ])->get();

    expect($typeFiltered)->toHaveCount(0);

    $formatter = app(InventoryReportFormatter::class);
    $values = $formatter->values(InventoryReportType::ConditionChanges, $rows->first(), false);

    expect($values[0])->toBe($postedDamage->document_number)
        ->and($values[1])->toBe(InventoryConditionChangeType::Damage->value);

    expect(InventoryExportType::ConditionChanges->reports())->toBe([InventoryReportType::ConditionChanges]);
});
