<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Sales\SalesAccountResolver;
use Database\Factories\SalesSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Singleton settings row for the Sales module, following the
 * {@see InventorySetting} / {@see PurchaseSetting} precedent
 * (data-model.md §1).
 *
 * Nullable account references are required only at posting time —
 * {@see SalesAccountResolver} refuses a posting whose
 * needed account is null, non-postable, or inactive (FR-007).
 *
 * @property string $default_tax_percent
 * @property int $default_quotation_validity_days
 */
#[Fillable([
    'default_tax_percent',
    'default_quotation_validity_days',
    'receivable_account_id',
    'revenue_account_id',
    'deferred_tax_account_id',
    'tax_payable_account_id',
    'customer_deposits_account_id',
])]
final class SalesSetting extends Model
{
    /** @use HasFactory<SalesSettingFactory> */
    use HasFactory;

    #[\Override]
    public function casts(): array
    {
        return [
            'default_tax_percent' => 'decimal:2',
            'default_quotation_validity_days' => 'integer',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'default_tax_percent' => 0,
            'default_quotation_validity_days' => 30,
        ]);
    }

    /** @return BelongsTo<ChartAccount, $this> */
    public function receivableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class, 'receivable_account_id');
    }

    /** @return BelongsTo<ChartAccount, $this> */
    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class, 'revenue_account_id');
    }

    /** @return BelongsTo<ChartAccount, $this> */
    public function deferredTaxAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class, 'deferred_tax_account_id');
    }

    /** @return BelongsTo<ChartAccount, $this> */
    public function taxPayableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class, 'tax_payable_account_id');
    }

    /** @return BelongsTo<ChartAccount, $this> */
    public function customerDepositsAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class, 'customer_deposits_account_id');
    }
}
