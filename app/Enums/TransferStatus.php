<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\StockTransfer;
use App\Services\Inventory\StockTransferService;

/**
 * Workflow status of a {@see StockTransfer} (FI-4).
 *
 * Single-confirm workflow: `draft` transitions to `confirmed` only, guarded
 * by {@see StockTransferService::confirm()}.
 * No intermediate in-transit case (spec Assumption; plan Open Question #9
 * resolved).
 */
enum TransferStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
}
