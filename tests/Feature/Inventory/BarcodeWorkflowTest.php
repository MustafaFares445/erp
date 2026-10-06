<?php

declare(strict_types=1);

use App\Enums\StockCondition;
use App\Filament\Pages\BarcodeWorkbench;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Services\Inventory\BarcodeResolver;
use App\Services\Inventory\BarcodeWorkflowService;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('rejects a valid scan that does not belong to the selected physical count', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->create();
    $variant = ProductVariant::factory()->create(['sku' => 'NOT-IN-THIS-COUNT']);
    $count = InventoryCount::factory()->counting()->create();
    Livewire::actingAs($actor)->test(BarcodeWorkbench::class)
        ->set('mode', 'count')
        ->set('countId', $count->id)
        ->set('scanCode', $variant->sku)
        ->call('scan')
        ->assertNotified(Notification::make()->danger()->title('Scan rejected')->body('The scanned item is not part of the selected inventory count.'))
        ->assertSet('matches', [])
        ->assertSet('countLineId', null);
    expect($count->lines()->count())->toBe(0);
});

it('resolves serial barcode and sku identifiers deterministically', function (): void {
    $variant = ProductVariant::factory()->create([
        'sku' => 'SKU-SCAN-001',
        'barcode' => 'BAR-SCAN-001',
        'name' => 'Scanner Variant',
    ]);
    $serialized = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->id,
        'serial_number' => 'SER-SCAN-001',
    ]);
    $resolver = app(BarcodeResolver::class);

    $serial = $resolver->resolve('  SER-SCAN-001  ');
    $barcode = $resolver->resolve('BAR-SCAN-001');
    $sku = $resolver->resolve('SKU-SCAN-001');

    expect($serial->kind)->toBe('serial')
        ->and($serial->serializedInventoryUnitId)->toBe($serialized->id)
        ->and($serial->productVariantId)->toBe($variant->id)
        ->and($barcode->kind)->toBe('barcode')
        ->and($barcode->productVariantId)->toBe($variant->id)
        ->and($sku->kind)->toBe('sku')
        ->and($sku->productVariantId)->toBe($variant->id);
});

it('matches operation lines without mutating inventory state', function (): void {
    $variant = ProductVariant::factory()->create(['barcode' => 'BAR-OP-001']);
    $operation = InventoryOperation::factory()->receipt()->ready()->create();
    $line = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $operation->id,
        'product_variant_id' => $variant->id,
        'unit_id' => $variant->unit_id,
        'quantity' => '4.000000',
    ]);
    $resolution = app(BarcodeResolver::class)->resolve('BAR-OP-001');
    $movementCount = InventoryMovement::query()->count();

    $matches = app(BarcodeWorkflowService::class)->operationMatches($operation, $resolution);

    expect($matches)->toHaveCount(1)
        ->and($matches->first()?->id)->toBe($line->id)
        ->and(InventoryMovement::query()->count())->toBe($movementCount)
        ->and($operation->refresh()->isReady())->toBeTrue();
});

it('records a scanned physical count through InventoryCountService', function (): void {
    $actor = User::factory()->create();
    $variant = ProductVariant::factory()->create(['barcode' => 'BAR-COUNT-001']);
    $count = InventoryCount::factory()->counting()->create();
    $line = InventoryCountLine::factory()->create([
        'inventory_count_id' => $count->id,
        'product_variant_id' => $variant->id,
        'stock_condition' => StockCondition::Saleable,
        'system_base_quantity' => '5.000000',
        'counted_base_quantity' => null,
    ]);
    $resolution = app(BarcodeResolver::class)->resolve('BAR-COUNT-001');

    $updated = app(BarcodeWorkflowService::class)->recordCount(
        $actor,
        $count,
        $line,
        $resolution,
        '4.000000',
    );

    expect($updated->counted_base_quantity)->toBe('4.000000')
        ->and($updated->variance_base_quantity)->toBe('-1.000000')
        ->and($count->refresh()->counted_by)->toBe($actor->id);
});
