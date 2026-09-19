<?php

declare(strict_types=1);

use App\Enums\ConditionChangeReason;
use App\Enums\InventoryConditionChangeStatus;
use App\Enums\InventoryConditionChangeType;
use App\Enums\StockCondition;
use App\Models\InventoryConditionChange;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\DisposalEvidenceSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function createDisposalEvidenceCoverageChange(): InventoryConditionChange
{
    $actor = User::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $warehouse = Warehouse::factory()->create();

    return InventoryConditionChange::query()->create([
        'document_number' => 'ICC-'.fake()->unique()->numerify('######'),
        'type' => InventoryConditionChangeType::Disposal,
        'status' => InventoryConditionChangeStatus::Draft,
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),        'condition_from' => StockCondition::Damaged,
        'condition_to' => StockCondition::Disposed,
        'base_quantity' => 1,
        'reason_category' => ConditionChangeReason::Other,
        'reason' => 'Coverage disposal evidence',
        'created_by' => $actor->getKey(),
    ]);
}

it('rejects missing or out-of-directory disposal evidence', function (): void {
    Storage::fake('local');
    $change = createDisposalEvidenceCoverageChange();
    $service = app(DisposalEvidenceSynchronizer::class);

    expect(fn () => $service->sync($change, ['elsewhere/missing.pdf']))
        ->toThrow(ValidationException::class);

    Storage::disk('local')->put('disposal-evidence/missing-after-prefix.pdf', '');
    Storage::disk('local')->delete('disposal-evidence/missing-after-prefix.pdf');

    expect(fn () => $service->sync($change, ['disposal-evidence/missing-after-prefix.pdf']))
        ->toThrow(ValidationException::class);
});
it('rejects oversized and unsupported disposal evidence files', function (): void {
    Storage::fake('local');
    $change = createDisposalEvidenceCoverageChange();
    $service = app(DisposalEvidenceSynchronizer::class);

    $oversized = 'disposal-evidence/oversized.pdf';
    Storage::disk('local')->put($oversized, str_repeat('x', (10 * 1024 * 1024) + 1));
    expect(fn () => $service->sync($change, [$oversized]))
        ->toThrow(ValidationException::class);

    $unsupported = UploadedFile::fake()->create('evidence.txt', 1, 'text/plain')
        ->storeAs('disposal-evidence', 'evidence.txt', 'local');
    expect(fn () => $service->sync($change, [$unsupported]))
        ->toThrow(ValidationException::class);
});

it('adds valid disposal evidence to media and removes the temporary upload', function (): void {
    Storage::fake('local');
    $change = createDisposalEvidenceCoverageChange();
    $path = UploadedFile::fake()->image('evidence.jpg')
        ->storeAs('disposal-evidence', 'evidence.jpg', 'local');

    app(DisposalEvidenceSynchronizer::class)->sync($change, [$path, $path]);

    expect($change->refresh()->getMedia('disposal-evidence'))->toHaveCount(1)
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});
