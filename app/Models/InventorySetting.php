<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['default_markup_percent', 'expiry_alert_days', 'max_price_floor_override_percent'])]
final class InventorySetting extends Model
{
    #[\Override]
    public function casts(): array
    {
        return [
            'default_markup_percent' => 'decimal:2',
            'expiry_alert_days' => 'integer',
            'max_price_floor_override_percent' => 'decimal:2',
        ];
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

    /**
     * How far below a variant's price floor an approver may go, as a
     * percentage of the floor. Null means no ceiling: an approver may
     * approve any price once a reason is given.
     */
    public static function maxPriceFloorOverridePercent(): ?float
    {
        $percent = self::query()->value('max_price_floor_override_percent');

        return is_numeric($percent) ? (float) $percent : null;
    }
}
