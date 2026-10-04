<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SupportAssignmentStrategy;
use App\Models\Concerns\TracksBlameable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code', 'name', 'description', 'assignment_strategy', 'default_capacity',
    'manager_user_id', 'is_active',
])]
final class SupportTeam extends Model
{
    use TracksBlameable;

    #[\Override]
    public function casts(): array
    {
        return [
            'assignment_strategy' => SupportAssignmentStrategy::class,
            'default_capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /** @return HasMany<SupportTeamMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(SupportTeamMember::class);
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** @return HasMany<SupportQueue, $this> */
    public function queues(): HasMany
    {
        return $this->hasMany(SupportQueue::class);
    }
}
