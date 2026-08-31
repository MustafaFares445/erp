<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Enums\InventoryPostingBalanceMode;
use App\Enums\MovementType;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
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
        public ?int $reversalOfMovementId = null,
        public ?string $transactionQuantity = null,
        public ?int $transactionUnitId = null,
        public ?string $conversionFactorSnapshot = null,
        public ?string $baseQuantityDelta = null,
        public ?string $lotOnHandBaseQuantityDelta = null,
        public ?string $lotReservedBaseQuantityDelta = null,
        public ?SerializedInventoryUnitStatus $serializedTargetStatus = null,
        public bool $serializedWarehouseSpecified = false,
        public ?int $serializedTargetWarehouseId = null,
        public ?SerializedCustodyType $serializedTargetCustodyType = null,
        public ?string $serializedTargetCustodyReferenceType = null,
        public ?int $serializedTargetCustodyReferenceId = null,
        public StockCondition $stockCondition = StockCondition::Saleable,
        public ?StockCondition $conditionFrom = null,
        public ?StockCondition $conditionTo = null,
        public ?string $conditionTransferBaseQuantity = null,
        public ?StockCondition $serializedTargetStockCondition = null,
        public bool $serializedInventoryLotSpecified = false,
        public ?int $serializedTargetInventoryLotId = null,
    ) {}
}
