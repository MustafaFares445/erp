<?php

declare(strict_types=1);

use App\Data\Inventory\CountScopeData;
use App\Enums\CountScope;
use App\Enums\InventoryPermission;
use App\Enums\StockCondition;
use App\Filament\Resources\InventoryCounts\Pages\ViewInventoryCount;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryCount;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryCountService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function countSheetActor(): User
{
    $actor = User::factory()->create();

    foreach ([InventoryPermission::CountView, InventoryPermission::CountOpen, InventoryPermission::CountRecord] as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission->value, 'web'));
    }

    return $actor;
}

/** @return array{0: InventoryCount, 1: ProductVariant, 2: Warehouse} */
function openedCountSheet(): array
{
    $actor = countSheetActor();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();

    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '10.000000', 'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000', 'available_quantity' => '10.000000',
    ]);
    InventoryConditionBalance::query()->forceCreate([
        'product_variant_id' => $variant->getKey(), 'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable,
        'on_hand_base_quantity' => '10.000000', 'reserved_base_quantity' => '0.000000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000000', 'reserved_quantity' => '0.000000',
    ]);
    InventoryLotBalance::query()->firstOrNew([
        'inventory_lot_id' => $lot->getKey(), 'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => StockCondition::Saleable->value,
    ])->forceFill([
        'on_hand_base_quantity' => '10.000000', 'reserved_base_quantity' => '0.000000',
    ])->save();

    $count = app(InventoryCountService::class)->open(new CountScopeData(
        warehouseId: $warehouse->getKey(),
        scopeType: CountScope::Warehouse,
        productCategoryId: null,
        inventoryLotId: null,
        productVariantIds: null,
        conditions: [StockCondition::Saleable->value],
        materialityThresholdMinor: null,
    ), $actor);

    return [$count, $variant, $warehouse];
}

/**
 * WP-3.5 (GAP-MW-06) — a clipboard count sheet round-trips: the download
 * gives every generated line with its system quantity, and the upload
 * re-applies counted quantities back onto those same lines.
 */
it('downloads a count sheet with one row per generated line', function (): void {
    [$count] = openedCountSheet();
    $line = $count->lines()->sole();

    $page = new ViewInventoryCount;
    $page->record = $count;

    $method = new ReflectionMethod($page, 'downloadCountSheet');
    $response = $method->invoke($page, $count);

    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toContain('line_id,sku,variant,lot,serial,condition,system_quantity,counted_quantity')
        ->and($csv)->toContain((string) $line->id)
        ->and($csv)->toContain('10.000000');
});

it('uploads counts and records them against the matching lines', function (): void {
    Storage::fake('local');
    $actor = countSheetActor();
    [$count] = openedCountSheet();
    $line = $count->lines()->sole();

    $csv = "line_id,sku,variant,lot,serial,condition,system_quantity,counted_quantity\n"
        .$line->id.",SKU,Variant,,,saleable,10.000000,8.500000\n";

    Livewire::actingAs($actor)
        ->test(ViewInventoryCount::class, ['record' => $count->getKey()])
        ->callAction(TestAction::make('upload_counts'), data: [
            'sheet' => UploadedFile::fake()->createWithContent('sheet.csv', $csv),
        ])
        ->assertHasNoActionErrors();

    expect($line->refresh()->counted_base_quantity)->toBe('8.500000');
});

it('rejects a row naming a line that does not belong to this count', function (): void {
    Storage::fake('local');
    $actor = countSheetActor();
    [$count] = openedCountSheet();
    [$otherCount] = openedCountSheet();
    $foreignLine = $otherCount->lines()->sole();

    $csv = "line_id,sku,variant,lot,serial,condition,system_quantity,counted_quantity\n"
        .$foreignLine->id.",SKU,Variant,,,saleable,10.000000,5.000000\n";

    Livewire::actingAs($actor)
        ->test(ViewInventoryCount::class, ['record' => $count->getKey()])
        ->callAction(TestAction::make('upload_counts'), data: [
            'sheet' => UploadedFile::fake()->createWithContent('sheet.csv', $csv),
        ])
        ->assertHasNoActionErrors();

    expect($foreignLine->refresh()->counted_base_quantity)->toBeNull();
});
