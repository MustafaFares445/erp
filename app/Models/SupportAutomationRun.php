<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SupportAutomationRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'support_automation_rule_id', 'event_uuid', 'subject_type', 'subject_id',
    'status', 'matched', 'actions_executed', 'error', 'executed_at',
])]
final class SupportAutomationRun extends Model
{
    #[\Override]
    public function casts(): array
    {
        return [
            'status' => SupportAutomationRunStatus::class,
            'matched' => 'boolean',
            'actions_executed' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SupportAutomationRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(SupportAutomationRule::class, 'support_automation_rule_id');
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
