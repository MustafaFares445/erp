<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AuditsSensitiveSettings;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use WeakMap;

#[Fillable(['default_markup_percent', 'expiry_alert_days', 'max_price_floor_override_percent'])]
final class InventorySetting extends Model
{
    use AuditsSensitiveSettings;

    /** @var WeakMap<Request, int>|null */
    private static ?WeakMap $expiryAlertDaysByRequest = null;

    #[\Override]
    protected static function booted(): void
    {
        self::saved(static function (): void {
            self::forgetExpiryAlertDays();
        });

        self::deleted(static function (): void {
            self::forgetExpiryAlertDays();
        });
    }

    /** @return list<string> */
    public static function sensitiveSettingColumns(): array
    {
        return ['max_price_floor_override_percent'];
    }

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
        $request = request();
        $memo = self::$expiryAlertDaysByRequest ??= new WeakMap;

        if (isset($memo[$request])) {
            return $memo[$request];
        }

        $days = self::query()->value('expiry_alert_days');
        $memo[$request] = is_numeric($days) ? max(0, (int) $days) : 30;

        return $memo[$request];
    }

    private static function forgetExpiryAlertDays(): void
    {
        self::$expiryAlertDaysByRequest = null;
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
