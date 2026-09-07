<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Enums\ConditionChangeReason;
use Spatie\LaravelData\Data;

final class RecoveryDraftData extends Data
{
    public function __construct(
        public int $reversesConditionChangeId,
        public string $baseQuantity,
        public ConditionChangeReason $reasonCategory,
        public string $reason,
    ) {}
}
