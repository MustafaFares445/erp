<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Pages\BarcodeWorkbench;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function coverage59InventoryUser(): User
{
    (new InventoryPermissionSeeder)->run();

    $role = Role::firstOrCreate([
        'name' => 'coverage59-inventory',
        'guard_name' => 'web',
    ]);
    $role->syncPermissions(InventoryPermission::values());

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('covers barcode workbench operation and count option branches plus selection guards', function (): void {
    $user = coverage59InventoryUser();
    $this->actingAs($user);

    $receipt = InventoryOperation::factory()->receipt()->draft()->create();
    $delivery = InventoryOperation::factory()->delivery()->draft()->create();
    $transfer = InventoryOperation::factory()->internalTransfer()->draft()->create();
    $done = InventoryOperation::factory()->receipt()->done()->create();
    $count = InventoryCount::factory()->counting()->create();
    $closedCount = InventoryCount::factory()->pendingReview()->create();

    $page = app(BarcodeWorkbench::class);

    $page->mode = 'unknown';
    expect($page->operationOptions())->toBe([]);

    $page->mode = 'receipt';
    expect($page->operationOptions())->toHaveKey($receipt->id);

    $page->mode = 'delivery';
    expect($page->operationOptions())->toHaveKey($delivery->id);

    $page->mode = 'transfer';
    expect($page->operationOptions())->toHaveKey($transfer->id);

    expect($page->countOptions())->toHaveKey($count->id);

    $selectedOperation = new ReflectionMethod(BarcodeWorkbench::class, 'selectedOperation');
    $selectedCount = new ReflectionMethod(BarcodeWorkbench::class, 'selectedCount');

    $page->mode = 'receipt';
    $page->operationId = $receipt->id;
    expect($selectedOperation->invoke($page)->is($receipt))->toBeTrue();

    $page->operationId = $delivery->id;
    expect(fn () => $selectedOperation->invoke($page))
        ->toThrow(DomainException::class, 'not open for this barcode mode');

    $page->operationId = $done->id;
    expect(fn () => $selectedOperation->invoke($page))
        ->toThrow(DomainException::class, 'not open for this barcode mode');

    $page->countId = $count->id;
    expect($selectedCount->invoke($page)->is($count))->toBeTrue();

    $page->countId = $closedCount->id;
    expect(fn () => $selectedCount->invoke($page))
        ->toThrow(DomainException::class, 'no longer open for counting');

    $page->mode = 'count';
    $page->countId = $count->id;
    expect($page->targetUrl())->toContain((string) $count->id);

    $page->mode = 'receipt';
    $page->operationId = $receipt->id;
    expect($page->targetUrl())->toContain((string) $receipt->id);

    $page->scanCode = 'x';
    $page->resolution = ['code' => 'x'];
    $page->matches = [['id' => 1]];
    $page->countLineId = 1;
    $page->updatedMode();

    expect($page->operationId)->toBeNull()
        ->and($page->countId)->toBeNull()
        ->and($page->scanCode)->toBe('')
        ->and($page->resolution)->toBeNull()
        ->and($page->matches)->toBe([])
        ->and($page->countLineId)->toBeNull();
});

it('covers successful and rejected barcode scans for operations and counts', function (): void {
    $user = coverage59InventoryUser();

    $variant = ProductVariant::factory()->create([
        'sku' => 'SKU-COVERAGE-59',
        'barcode' => 'BAR-COVERAGE-59',
    ]);
    $otherVariant = ProductVariant::factory()->create([
        'sku' => 'SKU-COVERAGE-59-OTHER',
    ]);

    $operation = InventoryOperation::factory()->receipt()->draft()->create();
    $operationLine = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $operation->id,
        'product_variant_id' => $variant->id,
    ]);

    Livewire::actingAs($user)
        ->test(BarcodeWorkbench::class)
        ->set('mode', 'receipt')
        ->set('operationId', $operation->id)
        ->set('scanCode', $variant->sku)
        ->call('scan')
        ->assertSet('matches.0.id', $operationLine->id)
        ->assertSet('scanCode', '');

    Livewire::actingAs($user)
        ->test(BarcodeWorkbench::class)
        ->set('mode', 'receipt')
        ->set('operationId', $operation->id)
        ->set('scanCode', $otherVariant->sku)
        ->call('scan')
        ->assertSet('matches', [])
        ->assertSet('scanCode', '');

    $count = InventoryCount::factory()->counting()->create();
    $countLine = InventoryCountLine::factory()->create([
        'inventory_count_id' => $count->id,
        'product_variant_id' => $variant->id,
    ]);

    Livewire::actingAs($user)
        ->test(BarcodeWorkbench::class)
        ->set('mode', 'count')
        ->set('countId', $count->id)
        ->set('scanCode', $variant->barcode)
        ->call('scan')
        ->assertSet('matches.0.id', $countLine->id)
        ->assertSet('countLineId', $countLine->id);
});

it('records a scanned inventory count and covers invalid cached resolution handling', function (): void {
    $user = coverage59InventoryUser();
    $this->actingAs($user);

    $variant = ProductVariant::factory()->create([
        'sku' => 'SKU-COVERAGE-59-COUNT',
        'barcode' => 'BAR-COVERAGE-59-COUNT',
    ]);
    $count = InventoryCount::factory()->counting()->create();
    $line = InventoryCountLine::factory()->create([
        'inventory_count_id' => $count->id,
        'product_variant_id' => $variant->id,
        'system_base_quantity' => '2.000000',
    ]);

    $component = Livewire::actingAs($user)
        ->test(BarcodeWorkbench::class)
        ->set('mode', 'count')
        ->set('countId', $count->id)
        ->set('scanCode', $variant->sku)
        ->call('scan')
        ->set('countQuantity', '3')
        ->call('recordCount');

    $component->assertSet('matches.0.id', $line->id);
    expect((string) $line->refresh()->counted_base_quantity)->toBe('3.000000');

    $page = app(BarcodeWorkbench::class);
    $page->mode = 'count';
    $page->countId = $count->id;
    $page->countLineId = $line->id;
    $page->resolution = ['code' => 123];

    expect(fn () => $page->recordCount())
        ->toThrow(DomainException::class, 'latest scan could not be resolved');
});
