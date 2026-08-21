<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PurchaseSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Singleton settings row for purchasing, following the {@see InventorySetting}
 * precedent (data-model.md §5).
 *
 * @property string $approval_threshold_amount
 * @property string $approval_threshold_currency
 */
#[Fillable(['approval_threshold_amount', 'approval_threshold_currency'])]
final class PurchaseSetting extends Model
{
    /** @use HasFactory<PurchaseSettingFactory> */
    use HasFactory;

    #[\Override]
    public function casts(): array
    {
        return ['approval_threshold_amount' => 'decimal:2'];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'approval_threshold_amount' => 0,
            'approval_threshold_currency' => 'AED',
        ]);
    }
}
