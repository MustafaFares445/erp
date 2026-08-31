<?php

declare(strict_types=1);

namespace App\Enums;

enum InventoryPostingBalanceMode
{
    /** The posting may only mutate a balance that already exists. */
    case RequireExisting;

    /** The posting is an inbound origin and may establish its balance row. */
    case CreateIfMissing;

    public function createsMissingBalance(): bool
    {
        return $this === self::CreateIfMissing;
    }
}
