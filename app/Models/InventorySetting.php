<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['default_markup_percent', 'expiry_alert_days'])]
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
}
