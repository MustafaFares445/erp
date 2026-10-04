<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'support_team_id', 'employee_id', 'capacity', 'routing_weight',
    'accepts_remote', 'accepts_onsite', 'is_active',
])]
final class SupportTeamMember extends Model
{
    #[\Override]
    public function casts(): array
    {
        return [
            'capacity' => 'integer',
            'routing_weight' => 'integer',
            'accepts_remote' => 'boolean',
            'accepts_onsite' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<SupportTeam, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(SupportTeam::class, 'support_team_id');
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_id');
    }
}
