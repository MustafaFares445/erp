<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'default_markup_percent',
    'expiry_alert_days',
    'inventory_asset_account_id',
    'cogs_account_id',
    'shrinkage_expense_account_id',
])]
final class InventorySetting extends Model
{
    #[\Override]
    public function casts(): array
    {
        return ['default_markup_percent' => 'decimal:2', 'expiry_alert_days' => 'integer'];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([], ['default_markup_percent' => 0, 'expiry_alert_days' => 30]);
    }

    public static function expiryAlertDays(): int
    {
        $days = self::query()->value('expiry_alert_days');

        return is_numeric($days) ? max(0, (int) $days) : 30;
    }

    /** @return BelongsTo<ChartAccount, $this> */
    public function inventoryAssetAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class, 'inventory_asset_account_id');
    }

    /** @return BelongsTo<ChartAccount, $this> */
    public function cogsAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class, 'cogs_account_id');
    }

    /** @return BelongsTo<ChartAccount, $this> */
    public function shrinkageExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class, 'shrinkage_expense_account_id');
    }
}
