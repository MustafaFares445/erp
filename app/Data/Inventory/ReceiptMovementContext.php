<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Models\InventoryLot;
use App\Models\InventoryReceipt;
use App\Models\InventoryReceiptItem;
use App\Models\User;
use Spatie\LaravelData\Data;

final class ReceiptMovementContext extends Data
{
    public function __construct(
        public InventoryReceiptItem $item,
        public InventoryReceipt $receipt,
        public ?InventoryLot $lot,
        public User $actor,
    ) {}
}
