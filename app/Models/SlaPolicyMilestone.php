<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SlaMilestoneKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sla_policy_id', 'key', 'target_minutes', 'at_risk_before_minutes',
    'pause_when_waiting_customer', 'is_active', 'sort_order',
])]
final class SlaPolicyMilestone extends Model
{
    #[\Override]
    public function casts(): array
    {
        return [
            'key' => SlaMilestoneKey::class,
            'pause_when_waiting_customer' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<SlaPolicy, $this> */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'sla_policy_id');
    }
}
