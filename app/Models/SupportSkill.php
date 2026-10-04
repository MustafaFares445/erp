<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['code', 'name', 'is_active'])]
final class SupportSkill extends Model
{
    #[\Override]
    public function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsToMany<EmployeeProfile, $this> */
    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(EmployeeProfile::class, 'support_employee_skills', 'support_skill_id', 'employee_id')
            ->withPivot('proficiency')
            ->withTimestamps();
    }
}
