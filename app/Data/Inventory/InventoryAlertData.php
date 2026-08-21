<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Enums\InventoryAlertSeverity;
use Spatie\LaravelData\Data;

final class InventoryAlertData extends Data
{
    /** @param array<string, mixed>|null $context */
    public function __construct(
        public string $message,
        public InventoryAlertSeverity $severity,
        public ?array $context = null,
    ) {}
}
