<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'support_team_id', 'is_active', 'sort_order', 'criteria', 'is_system'])]
final class SupportQueue extends Model
{
    use TracksBlameable;

    #[\Override]
    public function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'sort_order' => 'integer',
            'criteria' => 'array',
        ];
    }

    /** @return BelongsTo<SupportTeam, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(SupportTeam::class, 'support_team_id');
    }
}
