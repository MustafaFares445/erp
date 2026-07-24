<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\InventoryAdjustment;
use App\Services\Inventory\InventoryAdjustmentService;

/**
 * Workflow status of an {@see InventoryAdjustment} (FI-3).
 *
 * Single-confirm workflow: `draft` transitions to `confirmed` only, guarded
 * by {@see InventoryAdjustmentService::confirm()}.
 * No `pending` case (spec Assumption; plan Open Question #10 resolved).
 */
enum AdjustmentStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
}
