<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Enums\InventoryPostingBalanceMode;
use App\Enums\MovementType;
use App\Services\Inventory\QuantityNormalizer;
use Spatie\LaravelData\Data;

/**
 * One normalized stock posting at the current warehouse balance grain.
 *
 * All quantity fields are base-UOM decimal strings. Document workflows must
 * use {@see QuantityNormalizer} before constructing
 * this command; this service deliberately has no transaction-UOM fallback.
 */
final class InventoryPostingCommand extends Data
{
    public function __construct(
        public int $productVariantId,
        public int $warehouseId,
        public string $onHandBaseQuantityDelta,
        public string $reservedBaseQuantityDelta,
        public string $damagedBaseQuantityDelta,
        public MovementType $movementType,
        public string $movementBaseQuantityDelta,
        public string $sourceType,
        public int $sourceId,
        public ?int $actorId,
        public ?string $notes = null,
        public ?int $serializedInventoryUnitId = null,
        public ?string $idempotencyKey = null,
        public InventoryPostingBalanceMode $balanceMode = InventoryPostingBalanceMode::RequireExisting,
        public ?int $inventoryLotId = null,
        public ?int $packageId = null,
        public ?string $sourceLineType = null,
        public ?int $sourceLineId = null,
        public ?string $transactionQuantity = null,
        public ?int $transactionUnitId = null,
        public ?string $conversionFactorSnapshot = null,
        public ?string $baseQuantityDelta = null,
    ) {}
}
