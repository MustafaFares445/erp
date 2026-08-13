<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BonusSuggestionStatus;
use Database\Factories\BonusSuggestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'sales_plan_id',
    'sales_opportunity_id',
    'amount',
    'reason',
    'status',
    'approved_by',
    'approved_at',
    'decision_notes',
])]
final class BonusSuggestion extends Model
{
    /** @use HasFactory<BonusSuggestionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => BonusSuggestionStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<EmployeeProfile, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    /**
     * @return BelongsTo<SalesPlan, $this>
     */
    public function salesPlan(): BelongsTo
    {
        return $this->belongsTo(SalesPlan::class);
    }

    /**
     * @return BelongsTo<SalesOpportunity, $this>
     */
    public function salesOpportunity(): BelongsTo
    {
        return $this->belongsTo(SalesOpportunity::class, 'sales_opportunity_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
