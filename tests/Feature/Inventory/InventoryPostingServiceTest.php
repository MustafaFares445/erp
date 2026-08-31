<?php

declare(strict_types=1);

use App\Data\Inventory\InventoryPostingCommand;
use App\Enums\MovementType;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\User;
use App\Services\Inventory\InventoryPostingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('commits a locked balance mutation and immutable movement together', function (): void {
    $actor = User::factory()->create();
    $stock = InventoryStock::factory()->create([
        'on_hand_quantity' => '10.000',
        'reserved_quantity' => '2.000',
        'damaged_quantity' => '0.000',
        'available_quantity' => '8.000',
    ]);

    $posting = app(InventoryPostingService::class)->post(postingCommand(
        $stock,
        $actor,
        MovementType::Damage,
        ['damaged_delta' => '3.000', 'movement_delta' => '-3.000'],
    ));

    expect($posting->alreadyPosted)->toBeFalse()
        ->and($posting->balanceBefore->toAuditValues())->toBe([
            'on_hand_quantity' => 10.0,
            'reserved_quantity' => 2.0,
            'damaged_quantity' => 0.0,
            'available_quantity' => 8.0,
        ])
        ->and($posting->stock->fresh()->only([
            'on_hand_quantity',
            'reserved_quantity',
            'damaged_quantity',
            'available_quantity',
        ]))->toBe([
            'on_hand_quantity' => '10.000000',
            'reserved_quantity' => '2.000000',
            'damaged_quantity' => '3.000000',
            'available_quantity' => '5.000000',
        ])
        ->and($posting->movement->quantity)->toBe('-3.000000')
        ->and(InventoryMovement::query()->count())->toBe(1)
        ->and(fn (): bool => $posting->movement->forceFill(['notes' => 'rewritten'])->save())
        ->toThrow(LogicException::class, 'Inventory movements are immutable. Create a compensating movement instead.');
});

it('returns the original posting without applying an idempotent retry twice', function (): void {
    $actor = User::factory()->create();
    $stock = InventoryStock::factory()->create([
        'on_hand_quantity' => '4.000',
        'reserved_quantity' => '0.000',
        'damaged_quantity' => '0.000',
        'available_quantity' => '4.000',
    ]);
    $receiptPosting = postingCommand(
        $stock,
        $actor,
        MovementType::Receipt,
        [
            'on_hand_delta' => '6.000',
            'movement_delta' => '6.000',
            'idempotency_key' => 'phase-2-receipt-retry',
        ],
    );

    $initialPosting = app(InventoryPostingService::class)->post($receiptPosting);
    $retriedPosting = app(InventoryPostingService::class)->post($receiptPosting);

    expect($initialPosting->alreadyPosted)->toBeFalse()
        ->and($retriedPosting->alreadyPosted)->toBeTrue()
        ->and($retriedPosting->movement->getKey())->toBe($initialPosting->movement->getKey())
        ->and($stock->fresh()->on_hand_quantity)->toBe('10.000000')
        ->and(InventoryMovement::query()->count())->toBe(1);
});

it('rolls back the materialized balance when creating its ledger entry fails', function (): void {
    $actor = User::factory()->create();
    $stock = InventoryStock::factory()->create([
        'on_hand_quantity' => '10.000',
        'reserved_quantity' => '0.000',
        'damaged_quantity' => '0.000',
        'available_quantity' => '10.000',
    ]);
    $failedReceiptPosting = postingCommand(
        $stock,
        $actor,
        MovementType::Receipt,
        ['on_hand_delta' => '2.000', 'movement_delta' => '2.000', 'actor_id' => 999_999],
    );

    expect(fn (): mixed => app(InventoryPostingService::class)->post($failedReceiptPosting))
        ->toThrow(QueryException::class);

    expect($stock->fresh()->on_hand_quantity)->toBe('10.000000')
        ->and(InventoryMovement::query()->count())->toBe(0);
});

it('locks and posts a batch in canonical variant warehouse order', function (): void {
    $actor = User::factory()->create();
    $firstStock = InventoryStock::factory()->create([
        'on_hand_quantity' => '1.000',
        'reserved_quantity' => '0.000',
        'damaged_quantity' => '0.000',
        'available_quantity' => '1.000',
    ]);
    $secondStock = InventoryStock::factory()->create([
        'on_hand_quantity' => '1.000',
        'reserved_quantity' => '0.000',
        'damaged_quantity' => '0.000',
        'available_quantity' => '1.000',
    ]);

    $batchPostings = app(InventoryPostingService::class)->postMany([
        postingCommand($secondStock, $actor, MovementType::Receipt, ['on_hand_delta' => '2.000', 'movement_delta' => '2.000']),
        postingCommand($firstStock, $actor, MovementType::Receipt, ['on_hand_delta' => '3.000', 'movement_delta' => '3.000']),
    ]);

    expect($batchPostings)->toHaveCount(2)
        ->and($batchPostings[0]->stock->product_variant_id)->toBeLessThanOrEqual($batchPostings[1]->stock->product_variant_id)
        ->and($firstStock->fresh()->on_hand_quantity)->toBe('4.000000')
        ->and($secondStock->fresh()->on_hand_quantity)->toBe('3.000000');
});

/**
 * @param  array{on_hand_delta?: string, reserved_delta?: string, damaged_delta?: string, movement_delta?: string, actor_id?: int, idempotency_key?: string}  $effects
 */
function postingCommand(
    InventoryStock $stock,
    User $actor,
    MovementType $movementType,
    array $effects = [],
): InventoryPostingCommand {
    return new InventoryPostingCommand(
        productVariantId: inventoryPostingId($stock->product_variant_id),
        warehouseId: inventoryPostingId($stock->warehouse_id),
        onHandBaseQuantityDelta: $effects['on_hand_delta'] ?? '0.000',
        reservedBaseQuantityDelta: $effects['reserved_delta'] ?? '0.000',
        damagedBaseQuantityDelta: $effects['damaged_delta'] ?? '0.000',
        movementType: $movementType,
        movementBaseQuantityDelta: $effects['movement_delta'] ?? '0.000',
        sourceType: 'inventory_posting_test',
        sourceId: inventoryPostingId($stock->getKey()),
        actorId: $effects['actor_id'] ?? inventoryPostingId($actor->getKey()),
        idempotencyKey: $effects['idempotency_key'] ?? null,
    );
}

function inventoryPostingId(mixed $key): int
{
    if (is_int($key)) {
        return $key;
    }

    if (is_string($key) && ctype_digit($key)) {
        return (int) $key;
    }

    throw new LogicException('Inventory posting test fixtures must use integer identifiers.');
}
