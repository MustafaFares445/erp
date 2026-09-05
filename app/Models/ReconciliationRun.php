<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReconciliationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'scope',
    'invariant',
    'passed',
    'divergence_count',
    'detail',
    'started_at',
    'finished_at',
    'triggered_by',
    'trigger_source',
])]
final class ReconciliationRun extends Model
{
    #[\Override]
    public function casts(): array
    {
        return [
            'scope' => ReconciliationScope::class,
            'passed' => 'boolean',
            'detail' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
