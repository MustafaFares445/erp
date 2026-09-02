<?php

declare(strict_types=1);

use App\Enums\InventoryReportType;
use App\Enums\InventoryReturnDisposition;
use App\Enums\MovementType;
use App\Models\CustomerProfile;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryCorrectionService;
use App\Services\Inventory\InventoryLotReconciliationService;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\InventoryReportService;
use App\Services\Inventory\InventoryReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps the release lifecycle traceable from receipt through delivery return correction and report', function (): void {
    $warehouse = Warehouse::factory()->create();
    $customer = CustomerProfile::factory()->create();
    $actor = User::factory()->create();
    $variant = ProductVariant::factory()->expiryMaterial()->create();

    $operationService = app(InventoryOperationService::class);

    $receipt = InventoryOperation::factory()->receipt()->create([
        'destination_warehouse_id' => $warehouse->getKey(),
    ]);
    $receiptLine = $receipt->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '10.000000',
        'unit_id' => $variant->unit_id,
        'unit_cost' => '2.0000',
        'lot_number' => 'RELEASE-LOT-001',
        'expires_at' => now()->addYear(),
    ]);

    $operationService->markReady($receipt, $actor);
    $operationService->complete($receipt->refresh(), $actor);

    $postedReceiptLine = $receiptLine->refresh();
    $lotId = $postedReceiptLine->inventory_lot_id;

    expect($lotId)->toBeInt();

    $delivery = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $warehouse->getKey(),
        'customer_id' => $customer->getKey(),
    ]);
    $deliveryLine = $delivery->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '4.000000',
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $lotId,
    ]);

    $operationService->markReady($delivery, $actor);

    expect(InventoryStock::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->sole()
        ->reserved_quantity)->toBe('4.000000');

    $operationService->complete($delivery->refresh(), $actor);

    $saleMovement = InventoryMovement::query()
        ->where('source_type', 'inventory_operation')
        ->where('source_id', $delivery->getKey())
        ->where('source_line_id', $deliveryLine->getKey())
        ->sole();

    $returnService = app(InventoryReturnService::class);
    $return = $returnService->createCustomerReturn(
        $actor,
        $delivery->refresh(),
        $warehouse,
        'Release acceptance return',
    );
    $returnLine = $returnService->addCustomerLine(
        $return,
        $deliveryLine->refresh(),
        '2.000000',
        $lotId,
    );
    $returnService->inspectLine(
        $returnLine,
        InventoryReturnDisposition::Saleable,
        $actor,
        'Release acceptance inspection',
    );
    $returnService->markReady($return, $actor);
    $returnService->post($return->refresh(), $actor);

    $returnMovement = InventoryMovement::query()
        ->where('source_type', 'inventory_return')
        ->where('source_id', $return->getKey())
        ->sole();

    $correctionService = app(InventoryCorrectionService::class);
    $correction = $correctionService->createReceiptCorrection(
        $actor,
        $receipt->refresh(),
        'Release acceptance receipt correction',
    );
    $correctionLine = $correctionService->addReceiptLine(
        $correction,
        $receiptLine->refresh(),
        '1.000000',
    );
    $correctionService->post($correction->refresh(), $actor);

    $receiptMovement = InventoryMovement::query()
        ->where('source_type', 'inventory_operation')
        ->where('source_id', $receipt->getKey())
        ->where('source_line_id', $receiptLine->getKey())
        ->sole();
    $correctionMovement = InventoryMovement::query()
        ->where('source_type', 'inventory_correction')
        ->where('source_id', $correction->getKey())
        ->where('source_line_id', $correctionLine->getKey())
        ->sole();

    $stock = InventoryStock::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->sole();

    $reportedMovements = app(InventoryReportService::class)
        ->query(InventoryReportType::Movements, [
            'warehouse_id' => $warehouse->getKey(),
            'product_variant_id' => $variant->getKey(),
        ])
        ->get();

    $movementTypes = $reportedMovements
        ->pluck('movement_type')
        ->map(static fn (mixed $type): string => $type instanceof MovementType ? $type->value : (string) $type)
        ->all();

    expect($stock->on_hand_quantity)->toBe('7.000000')
        ->and($stock->reserved_quantity)->toBe('0.000000')
        ->and($saleMovement->reversal_of_movement_id)->toBeNull()
        ->and($returnMovement->reversal_of_movement_id)->toBe($saleMovement->getKey())
        ->and($correctionMovement->reversal_of_movement_id)->toBe($receiptMovement->getKey())
        ->and($returnLine->refresh()->posted_inventory_movement_id)->toBe($returnMovement->getKey())
        ->and($correctionLine->refresh()->posted_inventory_movement_id)->toBe($correctionMovement->getKey())
        ->and($movementTypes)->toContain(
            MovementType::Receipt->value,
            MovementType::Sale->value,
            MovementType::Return->value,
            MovementType::Correction->value,
        )
        ->and(app(InventoryLotReconciliationService::class)->inspect()['errors'])->toBe([]);
});
