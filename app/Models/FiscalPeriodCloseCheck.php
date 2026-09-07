<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PeriodCloseCheck;
use App\Services\Accounting\PeriodCloseChecklistService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One persisted verdict from a {@see PeriodCloseChecklistService}
 * run — the reconciliation pack AC-10 keeps as evidence for a period's close
 * (or reopen) decision (WP-2.5, GAP-MW-18).
 */
/**
 * @property int $id
 * @property int $fiscal_period_id
 * @property PeriodCloseCheck $check_key
 * @property bool $passed
 * @property array<string, mixed>|null $detail
 * @property Carbon $measured_at
 * @property int|null $reconciliation_run_id
 */
#[Fillable([
    'fiscal_period_id',
    'check_key',
    'passed',
    'detail',
    'measured_at',
    'reconciliation_run_id',
])]
final class FiscalPeriodCloseCheck extends Model
{
    /** @return BelongsTo<FiscalPeriod, $this> */
    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    /** @return BelongsTo<ReconciliationRun, $this> */
    public function reconciliationRun(): BelongsTo
    {
        return $this->belongsTo(ReconciliationRun::class);
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'check_key' => PeriodCloseCheck::class,
            'passed' => 'boolean',
            'detail' => 'array',
            'measured_at' => 'datetime',
        ];
    }
}
